# Public MCP endpoint: router, HTTPS, and inbound auth

This documents the **generic secure remote MCP endpoint** built in this pass: a real inbound-authenticated, HTTPS-terminated `/mcp` endpoint reachable from the public internet, safely. It does **not** connect ChatGPT -- see "ChatGPT" at the bottom, and [`docs/rc21-chatgpt-mcp.md`](rc21-chatgpt-mcp.md).

```text
Internet
  |  DNS: MCP_PUBLIC_HOST -> your public IPv4
  v
Router: WAN TCP 443 -> your Docker host's LAN IP, TCP 443  (80 too, for ACME/redirect)
  v
caddy container (deploy/caddy/Caddyfile)
  |  terminates real public TLS (automatic ACME), reverse-proxies ONLY /mcp and /healthz
  v
design-core-mcp:3000  (private Docker network only -- never published to 0.0.0.0)
  |  rate limit -> inbound Bearer auth -> MCP transport -> tool handler
  v
Design Core REST v2  ->  WordPress  ->  Elementor
```

Nothing except Caddy's 80/443 is ever exposed publicly. `design-core-mcp:3000`, WordPress, and MySQL all stay on the private `design-core-internal` Docker network.

## 1. Two separate secrets -- do not confuse them

| Secret | Direction | Env var |
|---|---|---|
| Design Core machine credential | bridge **to** WordPress | `DESIGN_CORE_SITE_LOCAL_TOKEN` |
| MCP inbound token | caller **to** bridge | `MCP_INBOUND_TOKEN` |

Generate the inbound token locally, at least 256 bits of entropy:

```bash
openssl rand -base64 48
```

Put it only in your (gitignored) `.env` as `MCP_INBOUND_TOKEN`. Never commit it, never log it, never put a real value in `.env.example`. `MCP_INBOUND_AUTH_MODE=token` (the default) makes the server refuse to start if the token is empty -- it never silently falls back to unauthenticated. `MCP_INBOUND_AUTH_MODE=none` exists only for explicit local-only development that never leaves this machine.

## 2. Start the stack, including the HTTPS gateway

```bash
cp .env.example .env
# edit .env: DB_PASSWORD/DB_ROOT_PASSWORD, DESIGN_CORE_SITE_LOCAL_TOKEN (see
# docs/mcp-local-wsl-docker.md step 3), MCP_INBOUND_TOKEN (openssl command above),
# and MCP_PUBLIC_HOST (your real domain, e.g. mcp.example.com).
docker compose up -d
```

`caddy` depends on `design-core-mcp`; both come up with the rest of the stack. Restarting just the gateway/bridge after a config or token change:

```bash
docker compose up -d design-core-mcp caddy   # picks up new env/Caddyfile without touching db/wordpress
```

## 3. Router: the one NAT rule required

Forward **only**:

```text
WAN TCP 443 -> <your Docker host's LAN IP> TCP 443
```

Port 80 too, if you want ACME's HTTP-01 challenge and the automatic HTTP->HTTPS redirect to work (Caddy renews its own certificate this way; without it you'd need to switch to DNS-01 with a registrar API token instead). Do **not** forward 3000, 8080, or 3306 -- nothing but Caddy's 80/443 should ever reach this host from the WAN.

**If this Docker host runs inside WSL2** (as in this repository's own development setup): the WSL2 VM has its own internal NAT'd IP (e.g. `172.17.x.x`), which is *not* the same as the Windows machine's real LAN IP the router can route to. Unless WSL2 is running in **mirrored networking mode** (`networkingMode=mirrored` in `.wslconfig`), you need an extra Windows-side hop -- either enable mirrored mode, or a manual forwarding path into the WSL2 VM's IP. **Verified on this repo's own dev machine (2026-09):** for a port published through Docker Compose's own `ports:` mapping, no manual `netsh interface portproxy` step is actually needed -- Docker Desktop's own backend process (`com.docker.backend.exe`) directly mirrors whatever bind scope the Compose file specifies (`0.0.0.0:8087:80` showed up as a real `0.0.0.0:8087` listener on Windows, confirmed via `Get-NetTCPConnection`/`Get-Process` over `powershell.exe`, backed by an existing program-scoped "Docker Desktop Backend" inbound Allow rule covering any port that binary listens on) with zero extra Windows-side configuration. `netsh portproxy` is a fallback only for a listener that ISN'T a Docker-published port (e.g. a bare process bound inside WSL2 outside Docker).

**CGNAT check**: if your ISP's WAN IP doesn't match what an external service reports as your public IP (e.g. `curl -4 https://ifconfig.me/ip`), or your router's WAN IP falls in `100.64.0.0/10`, you're behind Carrier-Grade NAT and port-forwarding cannot work at all -- no router rule fixes this. The alternative is a secure outbound tunnel (e.g. a reverse-tunnel service) instead of inbound port-forwarding.

## 3b. Why bare public-IP TLS (no domain at all) doesn't avoid the port-80/443 requirement

A later task in this series (2026-09) investigated skipping DNS entirely and getting a publicly-trusted certificate straight for the public IPv4 address, to keep a router forwarding only two non-standard ports (no 80/443). Findings, researched against current sources rather than assumed from older Let's Encrypt behavior:

- **Let's Encrypt DOES now issue IP address certificates** (General Availability since 2026-01-15, via the ACME `shortlived` certificate profile, ~160-hour/6.67-day lifetime -- see [the GA announcement](https://letsencrypt.org/2026/01/15/6day-and-ip-general-availability)). Caddy has supported requesting them since **2.10.1** (2025-09) for IPv4 via HTTP-01; confirm your image is at least that version (`docker compose exec caddy caddy version`). This is a real, current capability -- the old "public CAs can't certify bare IPs" assumption is now outdated.
- **This does not remove the port requirement.** Every ACME validation method Let's Encrypt supports for IP certificates -- `http-01` and `tls-alpn-01` (no `dns-01`; there's no DNS for a bare IP) -- validates by connecting to the identifier on a **fixed, non-configurable port**: 80 for `http-01`, 443 for `tls-alpn-01`. This is an ACME protocol/CA-policy constraint, not a Caddy or client-tooling limitation, and no ACME client or alternate public CA (Google Trust Services' `pki.goog` also supports IP certs; ZeroSSL/Buypass/SSL.com currently don't) can redirect that validation traffic to a different port.
- **Conclusion**: if a router policy forwards only non-standard ports (e.g. only 8087/3000, explicitly never 80/443), no public CA can issue *any* certificate for that public IP -- domain or bare-IP -- because issuance itself requires inbound reachability on 80 or 443. This is reported as `PUBLIC_IP_TLS_BLOCKED`, distinct from a Caddy bug or an outdated-capability assumption: it's a direct conflict between the router's own port policy and the ACME protocol's validation requirement. The only ways past it are (a) forward 80 and/or 443 for the validation window, (b) get a real domain and use `dns-01` (no inbound port needed at all), or (c) use an outbound-initiated secure tunnel product that handles TLS on the tunnel provider's own edge instead of this host.

## 4. DNS

One A record:

```text
MCP_PUBLIC_HOST  A  <your public IPv4>
```

If your IP isn't static, use a dynamic-DNS provider/client instead of a plain A record, or Caddy's automatic HTTPS will keep trying to issue a certificate for a hostname that no longer resolves to this host.

## 5. Health checks

Two endpoints, deliberately different exposure:

- `GET /healthz` -- public, unauthenticated, returns only `{"status":"ok"}`. No WordPress URL, token, site registry, or hostnames. This is the one Caddy and Docker's own healthcheck use.
- `GET /health` -- detailed per-site reachability, requires the same `Authorization: Bearer <MCP_INBOUND_TOKEN>` as `/mcp`, and is **not** routed by Caddy at all (see `deploy/caddy/Caddyfile` -- only `/mcp` and `/healthz` are proxied; everything else, `/health` included, gets a 404 from the edge even though the container itself still serves it internally).

```bash
# public, no token needed
curl https://<MCP_PUBLIC_HOST>/healthz

# detailed, from inside the private network (e.g. docker compose exec, or curl on the host
# against the container's own loopback-only dev port, never through Caddy)
curl -H "Authorization: Bearer $MCP_INBOUND_TOKEN" http://127.0.0.1:3000/health
```

## 6. Read smoke test

Any MCP Streamable HTTP client works. Minimal check that auth and the transport are both live:

```bash
curl -i -X POST https://<MCP_PUBLIC_HOST>/mcp \
  -H "Authorization: Bearer $MCP_INBOUND_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"smoke-test","version":"0.0.0"}}}'
```

Expect `HTTP/1.1 200`. No `Authorization` header, a malformed one, or the wrong token must all get `401` with `WWW-Authenticate: Bearer` instead. For a full real-client read path (`initialize` -> `listTools` -> `design_core_site_status`), see `mcp-server/test/local-e2e.test.ts` Phase 6 -- the same pattern, just pointed at `MCP_URL=https://<MCP_PUBLIC_HOST>/mcp`.

## 7. Disposable write + rollback test

Never test writes against a real page. `mcp-server/test/local-e2e.test.ts` is the reusable, safety-checked harness: it creates its own page (marked `_design_core_mcp_e2e_test=1`), drives snapshot -> preview -> update -> independent render verification -> history -> rollback -> exact-restoration check through the real MCP transport, and only deletes what it created (re-checking the marker first, in a `finally`). Point it at a public endpoint instead of the local one:

```bash
cd mcp-server
RUN_LOCAL_MCP_E2E=1 \
MCP_E2E_URL="https://<MCP_PUBLIC_HOST>/mcp" \
MCP_E2E_INBOUND_TOKEN="$MCP_INBOUND_TOKEN" \
MCP_E2E_WPCLI_CWD=<path to your compose project dir> \
node --test dist/test/local-e2e.test.js dist/test/local-e2e-negative.test.js
```

The negative-contracts file (`local-e2e-negative.test.ts`) additionally needs `MCP_E2E_WP_TOKEN` (the real Design Core machine credential, reused read-only) and `MCP_E2E_WP_HOST_URL` (WordPress reachable from wherever you run the test) to exercise `allowWrite:false` and wrong-credential scenarios against throwaway bridge instances.

## 8. Secret rotation

- **MCP inbound token**: generate a new one (`openssl rand -base64 48`), update `MCP_INBOUND_TOKEN` in `.env`, `docker compose up -d design-core-mcp`. Every existing caller with the old token gets `401` immediately.
- **Design Core machine credential**: wp-admin -> Design Core -> Remote Access -> **Rotate** (revokes the old token, issues a new one with the same name/scopes/environment). Update `DESIGN_CORE_SITE_LOCAL_TOKEN` in `.env`, `docker compose up -d design-core-mcp`.

Rotate independently -- they authenticate opposite directions and are never the same value.

## 8b. "none" auth mode can never accidentally go public

`MCP_INBOUND_AUTH_MODE=none` exists only for direct-host local development (running `node dist/src/server.js` yourself, no Docker) and is refused at startup unless **both** of these hold:

1. `MCP_ALLOW_INSECURE_LOCAL_AUTH=1` is set -- a second, explicit opt-in separate from the mode itself.
2. The server's own `HOST` is a hostname it can prove is loopback-only: exactly `127.0.0.1`, `::1`, or `localhost` -- never a LAN address, `0.0.0.0`, `::`, or a hostname that merely happens to resolve to loopback today.

This Compose stack's `design-core-mcp` service hardcodes `HOST=0.0.0.0` (see `docker-compose.yml`) -- so "none" mode cannot start there under any `.env` combination, by construction, not merely by convention. There is no supported way to make the Docker/public topology run unauthenticated.

## 9. Compensated history rollback -- what "safe" means here

`Change_Ledger::rollback()` is **conflict-aware and all-or-compensate**, not a database transaction (this stack never issues raw `START TRANSACTION`/`COMMIT`/`ROLLBACK` SQL). Every conflict check runs before the first mutation; if every restore stage (the Elementor document, its companion metadata, `post_content`, the Design Core page manifest) succeeds and is verified, the history entry is durably marked rolled back. If any stage fails partway through -- including the final ledger-mark write itself -- rollback() attempts to write every tracked field straight back to the exact pre-rollback state instead of leaving a blend of old and new:

- **Compensation succeeds**: the page is back to exactly how it was before this rollback attempt. The error is `design_core_history_restore_failed_compensated`; the entry is **not** marked rolled back and the same rollback may be retried once the underlying failure (e.g. a transient write error) is corrected.
- **Compensation itself fails**: a distinct, more serious `design_core_history_compensation_failed` is returned. The page may be left in a partially-restored state and needs manual investigation -- this is never reported as success, and the entry is never marked rolled back either way.

See `tests/runtime/history-rollback-compensation.php` for a real end-to-end proof of both outcomes against a disposable page.

## 9. Emergency shutdown

Take the public endpoint down without touching WordPress/the database:

```bash
docker compose stop caddy design-core-mcp
```

Or, to block writes only while keeping reads available, use wp-admin -> Design Core -> Remote Access -> Remote write settings (uncheck "Allow remote writes") instead -- see `docs/mcp-local-wsl-docker.md` step 6.

## What this does not do

- **Does not connect ChatGPT.** This endpoint is a generic, secure, bearer-token-authenticated remote MCP server. ChatGPT's remote-connector authorization is governed by the MCP Authorization spec (OAuth 2.1 + PKCE + Dynamic Client Registration) rather than a static bearer token as of this writing -- check OpenAI's current documentation before attempting to connect it. Do not weaken this endpoint's authentication just to make that onboarding easier; if/when OAuth support is added, it should sit alongside or in front of this token auth, not replace it with something weaker.
- **Does not fabricate LEVEL 3 (public WAN) success.** Router forwarding, DNS, and a real external vantage point are the operator's own infrastructure; see the main task report for exactly which of those were verified here versus left `BLOCKED_PENDING_USER_NETWORK_CONFIG`.
