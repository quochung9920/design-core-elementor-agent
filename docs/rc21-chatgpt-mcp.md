# rc21 ChatGPT Remote Control / MCP Bridge

rc21 exposes the existing Design Core pipeline to ChatGPT through a separate MCP Bridge service (`mcp-server/`), without giving ChatGPT direct access to WordPress, the database, or Elementor's private storage, and without duplicating any Design Core business logic in the bridge.

```text
ChatGPT
  |  secure remote transport (tunnel / your own reverse proxy)
  v
design-core-mcp   (Node/TypeScript, mcp-server/)
  |  MCP request -> schema validation -> auth -> site resolver -> Design Core REST request -> normalized MCP response
  v
Design Core Remote API v2   (design-core-elementor/v2, this plugin)
  |  every route composes an existing service; none reimplement Design IR/BuildPlan/Elementor/persistence logic
  v
Elementor  ->  WordPress
```

ChatGPT is the orchestrator. The MCP Bridge is a transport and permission boundary. Design Core is the engine. Elementor is the render/editor target. WordPress is the host. This order never reverses -- the bridge does not plan, map, persist, or judge visual similarity; it only forwards a schema-validated call and returns Design Core's answer.

## Safety boundaries

- Design Core Remote API v2 is additive; `design-core-elementor/v1` is untouched.
- Every v2 write/destructive route requires `confirm: true`; the MCP tool schemas require it too (`z.literal(true)`), so an unconfirmed call never reaches the network.
- `POST /pages/{id}/update` only executes an already-approved, hash-locked BuildPlan (`preview_id` + `plan_hash` from `/build/preview` or `/figma/preview`). A page that changed since the preview, or a `plan_hash` that doesn't match, is refused as a conflict -- never silently overwritten.
- The MCP Bridge never accepts a raw WordPress URL from the model. `site` is a Zod enum built from whatever sites are actually configured on the bridge (`DESIGN_CORE_SITE_<NAME>_URL/_TOKEN`); an unconfigured site name is refused before any network call.
- The MCP Bridge never sends a raw Elementor control path. `design_core_auto_correct` sends a high-level compare/correct request; Design Core's own `Visual_Correction_Applier` resolves the concrete element and control against the live `Control_Schema_Registry` and fails closed when it can't.
- Machine credentials are scoped (`design_core_read/preview/build/modify/publish/rollback`); a credential's Bearer token is verified server-side and never becomes a WordPress cookie session for authorization purposes (see "Machine authentication" below for how it does interact with WordPress's own capability checks).
- A global "Disable Remote Writes" switch (Design Core -> Remote Access) blocks every write/destructive route with HTTP 423 while leaving reads/previews untouched.
- Every write records into the existing Change Ledger; MCP-specific correlation metadata (request id, tool, credential id, preview id, plan hash, before/after hash, history entry id) is recorded separately and never includes a bearer token, password, or Figma token.

## Workflow

```text
READ -> PREVIEW -> APPROVAL -> EXECUTE -> VERIFY
```

"Read homepage":
```text
ChatGPT -> design_core_page_snapshot -> MCP Bridge -> Design Core REST v2 -> Page Snapshot
```

"Use this Figma and preview the change":
```text
ChatGPT -> design_core_preview_figma -> MCP Bridge -> Design Core
        -> Figma Transport -> Design IR -> BuildPlan Preview
        -> preview_id + plan_hash
```

"OK, update it":
```text
ChatGPT -> design_core_update_page (preview_id, plan_hash, confirm:true)
        -> preview/plan_hash/page-hash verification
        -> Design Core -> Elementor -> Persistence Service (save -> invalidate -> reload -> render -> verify)
        -> History entry
```

"Compare and auto-correct to 97%":
```text
ChatGPT -> design_core_auto_correct -> Visual Feedback -> safe native-control corrections
        -> Persistence -> compare again
```

"Undo that":
```text
ChatGPT -> design_core_rollback -> Change Ledger -> conflict check -> restore
```

## Design Core Remote API v2

Namespace: `design-core-elementor/v2`.

| Route | Composes |
|---|---|
| `GET /site/status` | `Site_Status` (new compact aggregator over `Capability_Scanner`, `Figma_Transport`, `Production_Readiness`, `Remote_Settings`) |
| `GET /pages/{id}/snapshot` | `Page_Snapshot` |
| `POST /build/preview` | `Build_Plan_Preview` |
| `POST /figma/preview` | `Figma_Transport` + `Figma_Design_IR_Adapter` + `Build_Plan_Preview` |
| `POST /pages/{id}/visual-feedback` | `Visual_Feedback_Engine` |
| `POST /pages/{id}/update` | `Conversion_Service::execute_approved_plan()` (new method, existing primitives) |
| `POST /pages/{id}/auto-correct` | `Visual_Correction_Service` |
| `POST /pages/{id}/publish` | `wp_update_post()` + `Change_Ledger` |
| `GET /history` | `Change_Ledger::summaries()` |
| `POST /history/{entry}/rollback` | `Change_Ledger::rollback()` |

`execute_approved_plan()` is the one genuinely new piece of Design Core logic in rc21: the existing pipeline (`Conversion_Service::convert()`) only ever created new pages via `wp_insert_post()`. Remote updates need to mutate an **existing** page from an already-approved, hash-locked plan instead. The new method reuses the same executor/adapter/persistence/registry-sync primitives `convert()` uses, but deliberately never re-runs `apply_registry_reuse()`/`import_assets()` -- both mutate the IR *before* `convert()` plans it, and `Build_Plan_Preview::preview_ir()` doesn't run them either, so re-running them post-approval could silently change what gets built without changing `plan_hash`.

## Permission model

New capabilities, granted to `administrator` by a versioned, idempotent migration (also re-checked on every load, since `register_activation_hook()` never fires for an in-place file update):

| Capability | Routes |
|---|---|
| `design_core_read` | site status, page snapshot, history |
| `design_core_preview` | build/figma preview, visual feedback |
| `design_core_modify` | update page, auto-correct |
| `design_core_publish` | publish page |
| `design_core_rollback` | rollback |

`design_core_build` exists in the capability set for future use but has no route mapped to it yet.

A REST v2 permission callback accepts either a normal wp-admin session with the matching capability, or a validated machine-credential Bearer token with the matching scope -- never both blended into one WP_User for authorization purposes.

## Machine authentication

`Design_Core_Elementor_Machine_Credential_Registry` issues tokens shaped `dcmcp_<16-hex-id>_<43-char-base64url-secret>`. Only `hash('sha256', $secret)` is ever persisted; the plain token is returned exactly once, at creation, from Design Core -> Remote Access in wp-admin. SHA-256 (not a slow KDF) is correct here because the entropy lives in 32 bytes of CSPRNG output, not a user-chosen password -- the same tradeoff Laravel Sanctum and GitHub/Stripe personal-access-token hashing make.

Each credential carries `scopes`, `environment`, `created_at`/`last_used_at`/`revoked_at`, and supports revoke/rotate. Verification rejects a malformed, unknown, revoked, or scope-mismatched token, and rate-limits per credential id via a bounded, self-expiring transient counter (default 120 requests/minute, filterable via `design_core_elementor_machine_credential_rate_limit`).

Two real deployment gaps surfaced by driving this end-to-end against a real Apache/mod_php WordPress install, both fixed:

1. **Header delivery.** `WP_REST_Request` only reads `$_SERVER['HTTP_*']` for headers. A stock Apache+mod_php install commonly never populates `HTTP_AUTHORIZATION` there at all (the same gap WordPress core's own Application Passwords feature has), even though `getallheaders()`/`apache_request_headers()` see it fine. Bearer auth falls back to those before giving up.
2. **Acting user.** Elementor's own Document API checks `current_user_can('edit_post', ...)` internally. A Bearer-only request has no WordPress session at all, so Elementor legitimately refused every save with "Access denied" regardless of the credential's Design Core scopes. A verified credential now calls `wp_set_current_user()` to the WordPress user who created it (`created_by`, tracked at creation) for the duration of the request -- the same pattern WordPress core Application Passwords and WooCommerce REST API keys both use. This does **not** change how `design-core-elementor/v2` authorizes requests; that still gates on the credential's scopes, never on `current_user_can()`. It only gives WordPress/Elementor's own internal capability checks a real user to check against. A credential created with no user context (e.g. raw WP-CLI) has no owner to act as, and Elementor-side writes correctly keep failing for it.

## Preview approval and idempotency

`Design_Core_Elementor_Preview_Ticket_Store` locks a preview's exact `BuildPlan` + normalized Design IR into a short-lived (default 15 minute), auto-expiring transient, keyed by a random `preview_id`. `plan_hash` is `Change_Ledger::hash_value()` of that plan; `current_page_hash` is the same over the page's live `_elementor_data` at preview time. Executing re-checks all three: the ticket still exists, the page id matches, `plan_hash` matches, and the page's live hash still matches -- a page changed since preview is refused as a conflict (`design_core_page_conflict`), never silently overwritten. A consumed ticket is deleted on success so it can't be replayed against an unrelated later mutation.

`Design_Core_Elementor_Idempotency_Store` gives every write route `Idempotency-Key` support: same key + same request fingerprint (hash of method+route+body) replays the stored result without re-running the operation; same key + a different fingerprint is refused as a conflict; a short "pending" state guards a concurrent duplicate request racing the first one. The MCP Bridge derives a default key (hash of tool + arguments, time-bucketed) when the caller doesn't supply one, so a same-arguments retry is safe by default.

## Audit

`Design_Core_Elementor_Remote_Audit_Log` correlates MCP write activity with the Change Ledger (via `history_entry_id`) instead of duplicating it, recording `request_id`/`site`/`tool`/`page_id`/`machine_credential_id`/`actor`/`preview_id`/`plan_hash`/`before_hash`/`after_hash`/`result`. It is never handed a bearer token, password, or Figma token -- those never flow into any `record()` call in the first place.

`rollback_available` is reported directly in `update_page`/`publish_page`'s own success response (via `Change_Ledger::rollback_available_for()`), not only discoverable later via a separate `GET /history` call -- a large page whose "before" snapshot couldn't be durably captured (e.g. an unwritable uploads directory degrading to a hash-only record) is flagged immediately.

## MCP Bridge

See `mcp-server/` and [`docs/mcp-local-wsl-docker.md`](mcp-local-wsl-docker.md) for setup. Ten tools, each with real MCP safety annotations (`readOnlyHint`/`destructiveHint`) so a client can tell read from write from destructive apart without guessing from the name:

- **READ:** `design_core_site_status`, `design_core_page_snapshot`, `design_core_preview_build`, `design_core_preview_figma`, `design_core_visual_feedback`, `design_core_history`
- **WRITE:** `design_core_update_page`, `design_core_auto_correct`, `design_core_publish_page`
- **DESTRUCTIVE:** `design_core_rollback`

Every write/destructive tool's schema requires `confirm: z.literal(true)` -- the SDK validates this before the handler ever runs, so confirmation is enforced at the transport boundary, not only server-side. Responses are compact by default (`detail: true` requests the full payload for `page_snapshot`/`preview_build`/`preview_figma`).

The bridge now requires its own inbound `Authorization: Bearer <MCP_INBOUND_TOKEN>` on every `/mcp` request (separate from, and never the same secret as, the WordPress-side machine-credential Bearer auth) -- see [`docs/mcp-public-router.md`](mcp-public-router.md) for the full HTTPS gateway, router, and token setup. This is still not ChatGPT-specific authentication: ChatGPT's remote-connector authorization is governed by the MCP Authorization spec (OAuth 2.1 + PKCE + Dynamic Client Registration) rather than a static bearer token field, so a static `MCP_INBOUND_TOKEN` is a generic secure-endpoint gate, not necessarily what ChatGPT's own connector UI will accept. Adding a real embedded OAuth authorization server is tracked as follow-up work rather than shipped unverified, since it can only be genuinely tested against a live ChatGPT OAuth handshake. **Re-check OpenAI's current documentation before connecting** -- ChatGPT's connector UI and its exact auth requirement can change. Never weaken or remove the bearer-token gate just to make that onboarding easier.

## CI caveat

GitHub Actions on this repository remains blocked by a pre-existing account billing lockout (`startup_failure`, zero jobs created on every push). This is unrelated to and unfixable from this codebase. All rc21 claims in this document and its commit history are backed by real local runs: `php -l` across the full tree, the standalone test suite (`tests/remote-api-v2/run.php` plus the rest), `npm run check && npm test` in `mcp-server/`, and end-to-end verification against a real local WordPress + Elementor + Elementor Pro stack -- not by a GitHub Actions run this repository cannot currently produce.
