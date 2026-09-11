# ChatGPT Web -> Design Core Owner API (API-only, no MCP)

This is the authoritative setup and usage guide for controlling WordPress, Elementor/Elementor Pro and Design Core from a **Custom GPT using GPT Actions**.

MCP is not required in this path.

```text
ChatGPT Web / Custom GPT
        |
        | HTTPS + Authorization: Bearer dcapi_...
        v
Design Core Owner API
/wp-json/design-core/v1
        |
        +-- Site Intelligence
        +-- Design Intelligence
        +-- Elementor runtime intelligence
        +-- BuildPlan preview/apply/verify
        +-- WordPress content/settings/menus/media
        +-- History/rollback
        |
        v
WordPress + Elementor + Elementor Pro
```

## 1. Security model

The owner API is deliberately stricter than the legacy remote API.

- The API starts **disabled** and **unclaimed**.
- The first administrator who explicitly claims API ownership becomes the single WordPress owner of the API.
- Only that owner can enable/disable the API or create/rotate/revoke `dcapi_*` credentials.
- API endpoints do **not** fall back to WordPress cookies. A valid `Authorization: Bearer dcapi_...` credential is required for every operation except the OpenAPI document.
- Credentials are bound to the owner user, the current Design Core environment, explicit Design Core scopes, an expiry date, revocation state and a fixed-window rate limit.
- The plain credential secret is shown only once. WordPress stores only a SHA-256 hash of the high-entropy secret.
- Elementor-managed pages cannot be edited through generic WordPress `post_content` mutation endpoints. Elementor writes stay behind Design Core preview -> `preview_id`/`plan_hash` -> confirm -> apply -> verify.
- Remote writes still obey the global Design Core write kill switch, environment binding, idempotency support, audit logging, conflict detection and rollback.
- Media URL import rejects obvious private/loopback/reserved network targets and is capped at 20 MB.
- Generic content deletion is **Trash only**, never force-delete. Trashing the current front page requires an extra confirmation.

A stolen API token can act with its granted scopes until it expires or is revoked. Keep the Custom GPT visibility **Only me**, do not paste the token into chats, and rotate it immediately if exposure is suspected.

## 2. WordPress setup

After deploying the plugin version containing the owner API:

1. Log in to WordPress as the account that should permanently own the API.
2. Open **Design Core -> API Access**.
3. Click **Claim API ownership for my user**.
4. Enable **Owner-only REST API**.
5. Create an API credential.
6. For full Design Core/WordPress control select all six scopes:

```text
design_core_read
design_core_preview
design_core_build
design_core_modify
design_core_publish
design_core_rollback
```

7. Use a short expiry during development (for example 30-90 days).
8. Copy the `dcapi_...` token immediately. It is never retrievable again.

### Rotate or revoke

Use **Design Core -> API Access**.

- **Rotate** revokes the current token and returns a new secret.
- **Revoke** immediately makes that credential unusable.

The API itself never exposes the stored token hash or secret.

## 3. Base URLs

Direct WordPress REST base:

```text
https://YOUR-WORDPRESS-HOST/wp-json/design-core/v1
```

OpenAPI schema:

```text
https://YOUR-WORDPRESS-HOST/wp-json/design-core/v1/openapi
```

Manifest:

```text
https://YOUR-WORDPRESS-HOST/wp-json/design-core/v1/manifest
```

If the WordPress host does not support pretty REST URLs yet, fix Apache/Nginx/Caddy rewrite rules before connecting GPT Actions. The `?rest_route=` fallback is useful for diagnosis, but the GPT Actions OpenAPI contract expects a stable normal HTTPS path.

A dedicated hostname such as `https://api.designcorehub.online/v1` is recommended for production. `deploy/caddy/Caddyfile.api-only` exposes only `/v1/*` and rewrites it to WordPress's `rest_route` form, so the public API remains stable even if upstream Apache pretty `/wp-json` rewriting is broken. Set `DESIGN_CORE_API_PUBLIC_URL` in WordPress to that public `/v1` URL so the generated OpenAPI schema advertises the correct server.

For example in `wp-config.php` or equivalent container configuration:

```php
define( 'DESIGN_CORE_API_PUBLIC_URL', 'https://api.designcorehub.online/v1' );
```

This constant contains no secret. It only tells the OpenAPI generator which public HTTPS base URL ChatGPT should call.

## 4. ChatGPT Web setup

OpenAI GPT Actions require an external API, authentication configuration and an OpenAPI schema.

Create a Custom GPT:

1. ChatGPT -> **GPTs** -> **Create**.
2. Keep visibility **Only me**.
3. Open **Actions** -> **Create new action**.
4. Authentication -> **API key** -> **Bearer**.
5. Paste the `dcapi_...` token in the secure authentication field. Do not put it in GPT Instructions or Knowledge.
6. Import or paste the OpenAPI document from `/wp-json/design-core/v1/openapi`.
7. Test `getSiteStatus`, `getSiteMap`, `getSiteDesignSystem` and `getElementorCapabilities` first.
8. Only after read-only verification, test a disposable draft page through plan -> preview -> apply -> verify -> rollback.

The Custom GPT should not also be configured with an App for the same workflow; this integration is Actions/API-only.

## 5. Recommended Custom GPT Instructions

Use this as the core instruction block:

```text
You are the owner operator for Design Core Elementor.

The Design Core Owner API is the authoritative interface to WordPress, Design Core,
Elementor and Elementor Pro. Use API Actions instead of inventing site state.

For Elementor work:
1. Read live site/design/runtime context first.
2. Use runtime widget search/schema instead of guessing widget controls.
3. Plan before mutation.
4. Create a draft page when a new Elementor page is needed.
5. Produce a Design Core preview before applying Elementor changes.
6. Never apply unless can_execute_safely=true and the user approved the plan/preview.
7. Apply using the exact preview_id and plan_hash returned by the approved preview.
8. Verify after every Elementor write.
9. Use visual feedback before visual correction when a reference exists.
10. Publish only when the user explicitly asks to publish.

For generic WordPress content:
- Do not write post_content on Elementor-managed content. Use Design Core page actions.
- Use expected_modified_gmt when updating an existing item when available.
- Use Trash, not permanent deletion.
- Treat settings/menu/media changes as consequential and explain them before execution.

Never attempt direct _elementor_data/_elementor_page_settings/private Elementor metadata,
SQL, arbitrary PHP, filesystem changes, plugin installation, theme installation or server
administration through this API. If the API does not expose a capability, report the gap.
```

## 6. Complete operation catalog

The current owner API exposes **42 operations**. `/manifest` reports the live count and metadata; `/openapi` is the machine-readable contract.

### Site and runtime intelligence

| operationId | Method | Path | Scope | Purpose |
|---|---|---|---|---|
| `getManifest` | GET | `/manifest` | read | API/security/operation manifest |
| `getSiteStatus` | GET | `/site/status` | read | WordPress/Elementor/Pro/Design Core status |
| `understandSite` | POST | `/understand` | read | Site map + design system + runtime capabilities + task plan |
| `getSiteMap` | GET | `/site/map` | read | Pages, menus, templates, theme, post types, registry counts |
| `getSiteDesignSystem` | GET | `/site/design-system` | read | Design Core tokens, Elementor Kit, layout, breakpoints |
| `searchSiteContent` | GET | `/site/search` | read | Search WordPress content and reusable Design Core registries |
| `getElementorCapabilities` | GET | `/elementor/capabilities` | read | Live Core/Pro runtime capabilities |
| `getElementorCatalog` | GET | `/elementor/catalog` | read | Live widget inventory |
| `searchElementorWidgets` | POST | `/elementor/widgets/search` | read | Deterministic runtime widget ranking |
| `getElementorWidgetSchema` | GET | `/elementor/widgets/{widget}` | read | Live control schema for one widget |
| `getMediaLibrary` | GET | `/media` | read | Search media metadata |
| `planTask` | POST | `/tasks/plan` | read | Mutation-free task planning |

### Design Intelligence

| operationId | Method | Path | Scope | Purpose |
|---|---|---|---|---|
| `getDesignIntelligenceStatus` | GET | `/design/status` | read | Design Intelligence runtime/catalog status |
| `recommendDesign` | POST | `/design/recommend` | preview | Design profile recommendation |
| `previewDesignSystem` | POST | `/design/preview` | preview | Recommendation -> Page Shell -> Design IR -> BuildPlan preview |
| `enrichDesignIR` | POST | `/design/enrich-ir` | preview | Enrich caller-supplied Design IR without mutation |
| `auditPageUX` | GET | `/pages/{id}/ux-audit` | read | UX quality audit for one page |
| `previewFigma` | POST | `/figma/preview` | preview | Figma -> normalized Design IR -> preview |
| `previewBuild` | POST | `/build/preview` | preview | HTML/CSS or Design IR -> BuildPlan preview |

### Elementor page lifecycle

| operationId | Method | Path | Scope | Purpose |
|---|---|---|---|---|
| `createDraftPage` | POST | `/pages` | build | Create a draft page only; no Elementor storage write |
| `getPageSnapshot` | GET | `/pages/{id}` | read | Read bounded page/Elementor snapshot |
| `applyPageBuild` | POST | `/pages/{id}/apply` | modify | Execute exact approved preview ticket |
| `verifyPage` | POST | `/pages/{id}/verify` | read | Snapshot + UX audit + history after write |
| `visualFeedback` | POST | `/pages/{id}/visual-feedback` | preview | Reference/candidate visual analysis |
| `autoCorrectPage` | POST | `/pages/{id}/auto-correct` | modify | Apply bounded approved corrections |
| `publishPage` | POST | `/pages/{id}/publish` | publish | Publish explicit page |
| `getHistory` | GET | `/history` | read | Change Ledger summaries |
| `rollbackHistory` | POST | `/history/{entry}/rollback` | rollback | Conflict-aware rollback of eligible history entry |

### Generic WordPress content

These endpoints are for normal WordPress content. Elementor-managed content is protected from generic `post_content` writes.

| operationId | Method | Path | Scope | Purpose |
|---|---|---|---|---|
| `listWordPressContent` | GET | `/wordpress/content` | read | List posts/pages/public or UI-managed CPT content |
| `getWordPressContent` | GET | `/wordpress/content/{id}` | read | Read one content item and Elementor-managed flag |
| `createWordPressContent` | POST | `/wordpress/content` | build | Create non-Elementor content |
| `updateWordPressContent` | POST | `/wordpress/content/{id}` | modify | Update safe post fields; publish also needs publish scope in the credential |
| `trashWordPressContent` | POST | `/wordpress/content/{id}/trash` | modify | Move to Trash; front page needs `confirm_front_page=true` |
| `restoreWordPressContent` | POST | `/wordpress/content/{id}/restore` | modify | Restore from Trash |

### WordPress site settings and navigation

| operationId | Method | Path | Scope | Purpose |
|---|---|---|---|---|
| `getWordPressSettings` | GET | `/wordpress/settings` | read | Site title/tagline/front-page/time settings |
| `updateWordPressSettings` | POST | `/wordpress/settings` | publish | Update bounded site settings |
| `getWordPressMenus` | GET | `/wordpress/menus` | read | List menus and items |
| `upsertWordPressMenuItem` | POST | `/wordpress/menus/{id}/items` | modify | Create/update one menu item |
| `trashWordPressMenuItem` | POST | `/wordpress/menus/{id}/items/{item}` | modify | Move one menu item to Trash |

### WordPress media management

| operationId | Method | Path | Scope | Purpose |
|---|---|---|---|---|
| `importWordPressMedia` | POST | `/wordpress/media/import` | build | Sideload one public remote file with SSRF/size guards |
| `updateWordPressMedia` | POST | `/wordpress/media/{id}` | modify | Update title/alt/caption/description |
| `trashWordPressMedia` | POST | `/wordpress/media/{id}/trash` | modify | Move attachment to Trash |

## 7. Scope meanings

```text
design_core_read
  Read site/runtime/page/history/content/settings/menu/media data.

design_core_preview
  Create mutation-free Design Core, Figma and visual previews.

design_core_build
  Create draft WordPress content/pages and import media.

design_core_modify
  Apply approved Elementor plans and modify bounded WordPress content/menu/media.

design_core_publish
  Publish Elementor pages, publish generic WordPress content, and change site-level settings.

design_core_rollback
  Execute conflict-aware Design Core rollback.
```

If you do not want ChatGPT to publish anything, create the credential without `design_core_publish`.

## 8. Required write contract

All owner API mutations require:

```json
{
  "confirm": true
}
```

Use a unique `idempotency_key` for consequential actions when the schema offers it:

```json
{
  "confirm": true,
  "idempotency_key": "page-123-apply-20260907-001"
}
```

If retrying the exact same operation after a timeout, reuse the same key and the exact same payload.

For Elementor apply, additionally require the exact preview ticket:

```json
{
  "preview_id": "...",
  "plan_hash": "...",
  "confirm": true,
  "idempotency_key": "..."
}
```

Do not regenerate or alter the BuildPlan between preview approval and apply.

## 9. Recommended Elementor workflow

### New page

```text
understandSite
  -> searchSiteContent
  -> getSiteDesignSystem
  -> getElementorCapabilities
  -> searchElementorWidgets
  -> getElementorWidgetSchema
  -> planTask
  -> user approves plan
  -> createDraftPage
  -> previewDesignSystem or previewBuild / previewFigma
  -> check can_execute_safely
  -> user approves preview
  -> applyPageBuild
  -> verifyPage
  -> visualFeedback (when reference exists)
  -> optional autoCorrectPage after approval
  -> publishPage only on explicit request
```

### Existing Elementor page

```text
getPageSnapshot
  -> understandSite(page_id)
  -> planTask(page_id)
  -> previewBuild(page_id)
  -> user approval
  -> applyPageBuild
  -> verifyPage
```

If the page changes after preview generation, Design Core's conflict gate should reject the apply rather than overwrite the concurrent edit.

## 10. Examples

### Understand the site

```bash
curl -sS -X POST \
  -H "Authorization: Bearer $DESIGN_CORE_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"brief":"Create a BIM engineering landing page using the current site design language.","page_id":0}' \
  https://YOUR-HOST/wp-json/design-core/v1/understand
```

### Create a draft Elementor target page

```bash
curl -sS -X POST \
  -H "Authorization: Bearer $DESIGN_CORE_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title":"BIM Engineering Services","confirm":true,"idempotency_key":"bim-page-create-001"}' \
  https://YOUR-HOST/wp-json/design-core/v1/pages
```

### Apply an approved preview

```bash
curl -sS -X POST \
  -H "Authorization: Bearer $DESIGN_CORE_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"preview_id":"PREVIEW_ID","plan_hash":"PLAN_HASH","confirm":true,"idempotency_key":"bim-apply-001"}' \
  https://YOUR-HOST/wp-json/design-core/v1/pages/123/apply
```

### Import media

```bash
curl -sS -X POST \
  -H "Authorization: Bearer $DESIGN_CORE_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://public.example.com/hero.webp","alt":"BIM coordination model","confirm":true,"idempotency_key":"media-bim-001"}' \
  https://YOUR-HOST/wp-json/design-core/v1/wordpress/media/import
```

Never print `$DESIGN_CORE_API_TOKEN` in logs or shell history if avoidable.

## 11. Error handling

Common errors:

```text
design_core_api_disabled
  Owner API is disabled in wp-admin.

design_core_api_auth_required
  No Bearer dcapi credential reached WordPress.

design_core_api_credential_malformed / invalid / unknown / expired / revoked
  API credential problem.

design_core_api_owner_required
  Credential is not owned by the configured API owner.

design_core_api_scope_forbidden
  Credential lacks the operation's scope.

design_core_api_credential_environment_mismatch
  Credential environment does not match this WordPress site.

design_core_remote_writes_disabled
  Global Design Core remote write kill switch is off.

design_core_confirmation_required
  A mutation was attempted without confirm=true.

design_core_preview_required / design_core_plan_hash_mismatch / design_core_page_conflict
  Elementor apply safety gate rejected the request.

design_core_wp_elementor_content_protected
  Generic WordPress content update attempted to write post_content for Elementor-managed content.

design_core_wp_media_url_unsafe
  Remote media URL failed public-network SSRF validation.
```

Do not bypass these errors with direct WordPress metadata or SQL. Fix the request or capability gap.

## 12. Verification checklist before using ChatGPT writes

```text
[ ] Plugin source deployed to the actual WordPress runtime
[ ] PHP lint passes
[ ] tests/gpt-actions-api/run.php passes
[ ] API owner claimed by the intended WordPress user
[ ] Owner API enabled
[ ] dcapi credential created and stored only in GPT Action auth
[ ] GET /openapi returns OpenAPI 3.1
[ ] authenticated GET /manifest returns operation_count=42
[ ] getSiteStatus returns API enabled/owner claimed
[ ] understandSite works read-only
[ ] createDraftPage tested on staging
[ ] preview/apply/verify tested on a disposable draft
[ ] rollback tested on that disposable draft
[ ] publish tested only when deliberately required
```

## 13. API-only deployment and MCP retirement

The repository may retain `mcp-server/` for compatibility/history, but the ChatGPT Web workflow does not depend on it.

After the owner API is verified end-to-end, the MCP deployment can be stopped separately without deleting data:

```bash
docker compose \
  -p design-core-mcp \
  --env-file /srv/stacks/design-core-mcp/.env \
  -f /srv/stacks/design-core-mcp/compose.yml \
  down
```

Do **not** use `down -v`; do not delete shared networks/volumes while migrating.

Remove the old public MCP route only after the Custom GPT Action path has been proven to work.

## 14. Deliberate boundaries

The owner API gives ChatGPT broad control over the website's **content, pages, Design Core, Elementor build lifecycle, menus, media and bounded site settings**.

It deliberately does **not** expose:

- arbitrary PHP execution;
- SQL/database console;
- filesystem or SSH;
- raw `_elementor_data` / private Elementor metadata mutation;
- plugin/theme installation or arbitrary activation/deactivation;
- WordPress core update operations;
- permanent force-delete endpoints.

Those are infrastructure/admin capabilities rather than design/content API capabilities and should be added only as explicit, separately reviewed endpoints if ever needed.
