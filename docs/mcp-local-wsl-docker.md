# MCP local WSL2/Docker setup

```text
Windows -> WSL2 -> Docker Compose -> { db, wordpress, design-core-mcp } -> Secure Tunnel -> ChatGPT
```

WordPress and MySQL are never exposed to the internet for ChatGPT's benefit. The MCP Bridge (or your own reverse proxy / OpenAI's Secure MCP Tunnel) is the only intended path in from outside this machine.

## 1. Start the stack

From WSL2, in this repository:

```bash
cp .env.example .env
# edit .env: set DB_PASSWORD/DB_ROOT_PASSWORD; leave DESIGN_CORE_SITE_LOCAL_TOKEN
# empty for now -- you'll create it in step 3 and come back to this.
docker compose build
docker compose up -d db wordpress
```

Install WordPress + Elementor + this plugin (the `wpcli` service runs on demand under the `tools` profile, not with plain `up`):

```bash
docker compose run --rm wpcli core install \
  --url=http://localhost:8080 --title="Design Core Dev" \
  --admin_user=admin --admin_password=<pick one> --admin_email=you@example.test --skip-email
docker compose run --rm wpcli plugin install elementor --activate
docker compose run --rm wpcli plugin activate design-core-elementor
docker compose run --rm wpcli rewrite structure '/%postname%/' --hard
```

**Known gotcha:** the official `wordpress` (Debian, `www-data` = uid 33) and `wordpress:cli` (Alpine, `www-data` = uid 82) images disagree on that uid for the shared `wordpress_data` volume, which can make `wp plugin install` fail with a permission error on a fresh volume. Fix once, scoped **only** to `uploads`/`upgrade` -- never the whole `wp-content` tree, which would reach through this repo's own bind mount into your real files:

```bash
docker compose run --rm -u root --entrypoint chown wpcli -R 82:82 \
  /var/www/html/wp-content/uploads /var/www/html/wp-content/upgrade
```

## 2. Check it's running

```bash
docker compose ps
```

`db` should show no published port at all; `wordpress` and `design-core-mcp` should each show `127.0.0.1:<port>->...`, never `0.0.0.0:<port>->...`. Visit `http://localhost:8080/wp-admin` from your Windows browser to confirm WordPress itself is reachable.

## 3. Create a machine credential

In wp-admin: **Design Core -> Remote Access -> Create credential**. Give it a name, check the scopes it needs (start with `design_core_read` + `design_core_preview`; add `design_core_modify`/`design_core_publish`/`design_core_rollback` only once you're ready to let it write). The plain token is shown **exactly once** -- copy it now.

Paste it into `.env` as `DESIGN_CORE_SITE_LOCAL_TOKEN`, then bring the bridge up:

```bash
docker compose up -d design-core-mcp
```

## 4. Check the bridge

```bash
curl -s http://127.0.0.1:3000/health | python3 -m json.tool
```

Expect `"wordpress_reachable": true` and `"design_core_api_reachable": true`. If either is `false`, check `docker compose logs design-core-mcp` -- a common cause is `DESIGN_CORE_SITE_LOCAL_TOKEN` still empty/placeholder, or a typo in `DESIGN_CORE_SITE_LOCAL_URL` (it must be `http://wordpress`, the Docker service name -- never `http://localhost:8080`, which only resolves from the WSL2/Windows host, not from inside another container).

The bridge's own MCP endpoint is `POST http://127.0.0.1:3000/mcp` (Streamable HTTP). Any MCP-compatible client can connect directly for local testing before wiring up a tunnel.

## 5. Revoke or rotate a credential

**Design Core -> Remote Access**: **Revoke** immediately invalidates the token (the next request gets HTTP 401, regardless of any in-flight rate-limit or cache state). **Rotate** revokes the old token and issues a new one with the same name/scopes/environment, shown once, the same way as creation.

## 6. Disable / re-enable remote writes

**Design Core -> Remote Access -> Remote write settings**: uncheck "Allow remote writes" and save. Every write/destructive route (`update_page`, `auto_correct`, `publish_page`, `rollback`) immediately starts returning HTTP 423 (Locked); `site_status`, `page_snapshot`, `history`, `build/figma preview`, and `visual_feedback` are unaffected. Re-check the box to restore write access. This is a single global switch per site, independent of any credential's scopes.

## 7. Connect ChatGPT

ChatGPT's remote-connector setup (Settings -> Connectors -> Developer mode, or your account's equivalent) and its exact authentication requirement can change -- **check OpenAI's current documentation at connect time** rather than relying on steps written here. As of this writing, ChatGPT's remote MCP connectors are governed by the MCP Authorization spec (OAuth 2.1 + PKCE + Dynamic Client Registration); the bridge's own `/mcp` endpoint requires a static `Authorization: Bearer <MCP_INBOUND_TOKEN>` (see [`docs/rc21-chatgpt-mcp.md`](rc21-chatgpt-mcp.md)), which is a generic secure-endpoint gate rather than ChatGPT-specific auth, so the realistic path today is:

1. Expose `design-core-mcp` publicly behind real HTTPS with that inbound token still enforced -- either this repository's own Caddy gateway (`docker compose up -d caddy`; see [`docs/mcp-public-router.md`](mcp-public-router.md) for the router/DNS/token steps) or a secure tunnel you control (OpenAI's Secure MCP Tunnel for private/local development, or your own reverse proxy) -- never by publishing the container's port with `0.0.0.0`, and never by disabling the inbound token to make onboarding easier.
2. Point ChatGPT's custom connector at that gateway/tunnel's public HTTPS URL, following whatever auth mode the current ChatGPT UI offers for it.
3. Verify with a low-risk read call first (`design_core_site_status` or `design_core_page_snapshot`) before ever approving a write.

## Local -> staging -> production

`DESIGN_CORE_SITE_<NAME>_ENVIRONMENT` (default `local`) is separate from a site's `allow_write`/URL/token -- add more `DESIGN_CORE_SITE_<NAME>_*` env blocks to `mcp-server`'s environment (or your own compose override) to bind additional sites, each behind its own machine credential. A tool call's `site` argument is always one of these preconfigured names, never a URL the model supplies. Production environments should additionally keep **Design Core -> Remote Access -> Remote write settings** set to the `production` environment on that site so `Remote_Write_Guard::ensure_production_guard()` enforces the stricter confirm+preview+hash+capability+write-enabled combination described in `docs/rc21-chatgpt-mcp.md`.
