# ChatGPT MCP Abilities

How Design Core exposes the Owner API (`design-core/v1`) as WordPress
Abilities API entries so an Abilities-aware MCP connector -- in production on
this site, the **MCP Server For WordPress** plugin (`miniorange-secure-mcp-server`,
namespace `MoSMCP\`) -- can let ChatGPT discover and call them directly,
without a `dcapi_*` Bearer token ever entering the chat.

## Architecture

```
ChatGPT
  |  MCP (Streamable HTTP, OAuth-bound to one WordPress user)
  v
MoSMCP MCP Server  (wp-json/mosmcp/v1/mcp)
  |  wp_get_abilities() + WP_Ability::execute()
  v
WordPress Abilities API  (wp_register_ability, hook: wp_abilities_api_init)
  |
  v
Design_Core_Elementor_MCP_Ability_Bridge   (core/mcp-ability-bridge.php)
  |  builds a synthetic WP_REST_Request, calls the same public method
  |  the design-core/v1 REST route calls for that operation
  v
Design_Core_Elementor_GPT_Actions_Rest_Controller   (core/gpt-actions-rest-controller.php)
  |
  v
Design Core services (site intelligence, build-plan preview/apply,
change ledger, Elementor runtime introspection, ...)
  |
  v
WordPress + Elementor + Elementor Pro
```

The bridge is a **transport adapter only**. It never re-implements Design Core
logic -- every ability calls the exact same controller method the
`design-core/v1` REST route already registers for that operation, so preview/
plan-hash checks, confirm, idempotency, write guards, audit logging and
history/rollback all run through the one existing code path (`core/rest-
controller-v2.php`, `core/site-intelligence-rest-controller.php`, `core/
design-intelligence-rest-controller.php`, `core/api-platform-rest-
controller.php`). REST and Abilities/MCP stay two transports over one service
layer -- neither can drift from the other's guarantees.

## Root cause of `discover_abilities` returning `count: 0`

Two independent things had to both be true for the connector to see any
Design Core abilities, and originally neither was:

1. **The WordPress Abilities API only registers abilities lazily.**
   `wp_abilities_api_init` does not fire during normal request bootstrap --
   it fires the first time something calls `WP_Abilities_Registry::get_instance()`
   (see `wp-includes/abilities-api/class-wp-abilities-registry.php`), which in
   practice means the *first* `wp_get_abilities()` call of a request. Design
   Core's hook registration (`includes/class-plugin.php`, hooked in the
   constructor) was already correct; this is just how the WordPress-core
   framework itself defers ability registration, and it now works because
   MoSMCP's own request path calls `wp_get_abilities()`.

2. **MoSMCP gates *every* ability behind a per-role grant on an explicit "NHI"
   (Non-Human Identity) record**, not just "ability exists in the registry."
   `MoSMCP\Common\Controllers\MCP\REST_Controller::authenticate()` resolves
   the connecting WordPress user's roles, then calls
   `NHI_Store::resolve_for_roles($roles)` to get that user's allowed-ability
   list from the union of every *enabled* NHI's `role_ability_map` -- a
   snapshot stored in MoSMCP's own DB tables, edited from **wp-admin -> MCP
   Server -> Abilities** (`admin.php?page=mosmcp-abilities`). If no NHI exists
   or none is enabled, every MCP call (including `tools/list`) fails outright
   with JSON-RPC error `-32001` ("No NHI is enabled..."), which a connector
   can easily surface as "0 abilities." If an NHI exists but its
   `role_ability_map` predates a plugin registering new abilities, those new
   abilities are simply absent from the list -- the map is **not** re-derived
   from `wp_get_abilities()` on every request, it is a stored selection.

   On this site, an NHI named "ChatGPT" already existed and was enabled, so
   the failure mode was case 2's second half: its `administrator` grant
   (`role_ability_map['administrator']`) was captured before this change
   added Design Core's 27 new abilities, so `tools/list` legitimately
   returned everything *except* them. This is not a bug in MoSMCP or in
   Design Core -- it is by design (an admin must explicitly grant new tools
   to an agent, the same way a human wouldn't automatically get elevated
   access after someone else installs a new plugin). Verified live via
   `MoSMCP\Common\Repositories\NHI_Store::update()` (the same store method the
   plugin's own admin screen calls) to add the 27 new `design-core/*` ability
   names to the existing `administrator` grant -- a minimal, additive change,
   not a wholesale re-grant.

If `discover_abilities` ever reports 0 again, check in this order:
`GET wp-json/mosmcp/v1/health` (`has_nhi`/`nhi_count`) -> wp-admin -> MCP
Server -> Abilities (is the relevant NHI enabled, and does its grant include
the abilities you expect?) -> `wp_get_abilities()` from a WP-CLI/PHP shell
(is the ability actually registered at all?).

## Ability catalog

27 Owner API operations are bridged 1:1. Ability names are derived
deterministically from each operation's `operationId`
(`getSiteStatus` -> `design-core/get-site-status`) by
`Design_Core_Elementor_MCP_Ability_Bridge::ability_slug()` -- there is no
separate hand-maintained name list to drift out of sync; see
`Design_Core_Elementor_MCP_Ability_Bridge::OPERATION_METHODS` for the single
explicit mapping this can't derive automatically (ability -> controller
method), and `Design_Core_Elementor_GPT_Actions_API::operations()` for every
description, capability and input schema.

Two operations expose a friendlier field name than their REST path token:
`getPageSnapshot`'s and five other page-scoped operations' REST `id` becomes
`page_id` in the ability's input schema (`FIELD_ALIASES` in the bridge),
since a standalone ability call has no URL to give a bare `id` context from.

### Phase 1 -- read-only (minimum viable connector)

| Ability | Capability | Maps to |
|---|---|---|
| `design-core/get-site-status` | `design_core_read` | `getSiteStatus` |
| `design-core/get-site-map` | `design_core_read` | `getSiteMap` |
| `design-core/get-site-design-system` | `design_core_read` | `getSiteDesignSystem` |
| `design-core/search-site-content` | `design_core_read` | `searchSiteContent` |
| `design-core/get-elementor-capabilities` | `design_core_read` | `getElementorCapabilities` |
| `design-core/get-elementor-catalog` | `design_core_read` | `getElementorCatalog` |
| `design-core/search-elementor-widgets` | `design_core_read` | `searchElementorWidgets` |
| `design-core/get-elementor-widget-schema` | `design_core_read` | `getElementorWidgetSchema` |
| `design-core/get-media-library` | `design_core_read` | `getMediaLibrary` |
| `design-core/plan-task` | `design_core_read` | `planTask` |
| `design-core/get-page-snapshot` | `design_core_read` | `getPageSnapshot` |
| `design-core/get-history` | `design_core_read` | `getHistory` |

### Phase 2 -- preview (mutation-free)

| Ability | Capability | Maps to |
|---|---|---|
| `design-core/understand-site` | `design_core_read` | `understandSite` |
| `design-core/get-design-intelligence-status` | `design_core_read` | `getDesignIntelligenceStatus` |
| `design-core/recommend-design` | `design_core_preview` | `recommendDesign` |
| `design-core/preview-design-system` | `design_core_preview` | `previewDesignSystem` |
| `design-core/enrich-design-ir` | `design_core_preview` | `enrichDesignIR` |
| `design-core/audit-page-ux` | `design_core_read` | `auditPageUX` |
| `design-core/preview-figma` | `design_core_preview` | `previewFigma` |
| `design-core/preview-build` | `design_core_preview` | `previewBuild` |
| `design-core/visual-feedback` | `design_core_preview` | `visualFeedback` |

### Phase 3 -- controlled write

| Ability | Capability | Maps to | Notes |
|---|---|---|---|
| `design-core/create-draft-page` | `design_core_build` | `createDraftPage` | Draft only; never writes Elementor storage. |
| `design-core/apply-page-build` | `design_core_modify` | `applyPageBuild` | Requires the exact `preview_id` + `plan_hash` from a prior preview, and `confirm:true`. |
| `design-core/verify-page` | `design_core_read` | `verifyPage` | Read-only post-apply check. |
| `design-core/auto-correct-page` | `design_core_modify` | `autoCorrectPage` | Governed visual-correction loop; fails closed if no safe control maps. |
| `design-core/rollback-history` | `design_core_rollback` | `rollbackHistory` | Conflict-aware; refuses if the page changed since. |
| `design-core/publish-page` | `design_core_publish` | `publishPage` | Separate, strongest capability. Never called implicitly by any other ability. |

## Permission model

Every ability except the two internal meta-abilities inherited from
`core/agent-gateway.php` (`design-core/list-tools`, `get-tool-schema`,
`call-tool` -- pre-existing, kept `mcp.public=false`, gated on
`manage_options`, unchanged by this work) is gated by
`current_user_can($operation['capability'])`, using the same six real
WordPress capabilities the Owner API's Bearer credentials are scoped to
(`design_core_read/preview/build/modify/publish/rollback`, defined in
`core/design-core-capabilities.php`). There is no separate, weaker ability-
layer permission model -- a WordPress user must hold the underlying
capability the same way an owner API credential must hold the matching scope.

The connector's own OAuth layer decides *which WordPress user* is calling
(one MoSMCP NHI grant is scoped to that user's WordPress role); Design Core's
`current_user_can()` check decides *what that call is allowed to do*, exactly
as it would for the same user acting through wp-admin.

Downstream write-guards (`Design_Core_Elementor_Remote_Write_Guard::
ensure_credential_environment_match()`, audit-log actor attribution) already
had a "type=user" principal shape reserved for logged-in-user calls (used
elsewhere by every REST controller when auth resolves to cookies rather than
a machine credential); the bridge sets `_design_core_principal` to
`{'type':'user','id':get_current_user_id()}` for every ability call, so
environment checks correctly no-op (they only apply to `dcapi_*`/machine
credentials) and history/audit entries correctly attribute the real
WordPress user.

## Configuring the connector (MCP Server For WordPress)

1. wp-admin -> **MCP Server -> Abilities** (`admin.php?page=mosmcp-abilities`).
2. Under NHI (Non-Human Identity) management, create or edit the NHI
   representing ChatGPT's connection, enable it, and grant the
   `design-core/*` abilities you want that connecting user's role to have
   (`design-core/get-site-status`, etc.) -- or grant "all" for a first pass,
   then narrow later.
3. Complete the connector's OAuth flow from ChatGPT as normal; the token is
   bound to the WordPress user who authorized it, and that user's role
   determines which of the NHI's grants actually apply.
4. Re-run `discover_abilities` (`tools/list`) from ChatGPT -- newly-granted
   abilities appear immediately (`resolve_for_roles()` reads the grant live
   on every request; only the *set of abilities offered in the grant editor*
   depends on `wp_get_abilities()` having run at least once in that same
   request, which loading the Abilities screen itself guarantees).

Granting new abilities to an existing NHI is additive only, done through
`NHI_Store::update()` -- the same store method wp-admin's own grant editor
calls. No token is ever read or displayed.

## Safety boundaries preserved

- `dcapi_*` Owner API Bearer tokens are never generated, stored, or read for
  this transport -- the model only ever needs the connector's own OAuth
  session, never a Design Core credential.
- No ability can run raw PHP, SQL, shell, or filesystem access, install a
  plugin/theme, read `.env`/`wp-config.php`, or write `_elementor_data`
  directly. Elementor content changes only ever flow through
  `preview-build` -> `apply-page-build`'s existing plan-hash + confirm gate.
- `publish-page` requires its own `design_core_publish` capability, separate
  from `design_core_build`/`modify`, and is never invoked by any other
  ability.
- `rollback-history` reuses the existing conflict-aware rollback (refuses if
  the page changed since, or the entry was already rolled back).
- The REST API (`/wp-json/design-core/v1/*`) is unaffected by any of this --
  same anonymous-401/423, same Bearer-only auth, verified after this change
  (see Tests below).

## Tests

- `tests/mcp-abilities/run.php` -- standalone contract test (no WordPress
  needed): every bridged operation exists in
  `Design_Core_Elementor_GPT_Actions_API::operations()`, ability names match
  the documented catalog exactly (no drift), no ability name resembles a
  forbidden dangerous operation, every schema is well-formed with required
  fields actually declared, every capability is one of the six real Design
  Core scopes, `publish-page` requires `design_core_publish` specifically,
  and every mutating ability's schema requires `confirm`.
- Live verification (run inside the WordPress container, since it needs the
  real Abilities API + MoSMCP's own `MCP_Server`/`NHI_Store` classes):
  `wp_get_abilities()` count, `WP_Ability::check_permissions()` /
  `execute()` for all 27 abilities (read, preview, and a full disposable-page
  write E2E: plan -> create draft -> preview -> apply -> verify -> history ->
  rollback -> verify), anonymous `check_permissions()` denial, and
  `MoSMCP\Common\Services\MCP\MCP_Server::dispatch('tools/list')` through the
  exact same code path the connector uses.

## Troubleshooting checklist

| Symptom | Check |
|---|---|
| `discover_abilities` / `tools/list` returns a JSON-RPC error, not an empty list | `GET wp-json/mosmcp/v1/health` -> `has_nhi`/`nhi_count`. If `0`, enable an NHI in wp-admin -> MCP Server -> Abilities. |
| `tools/list` succeeds but is missing `design-core/*` | The connecting user's role isn't granted those abilities in any enabled NHI's `role_ability_map` -- edit the NHI's grant. |
| A specific `design-core/*` ability is missing even after granting "all" | Confirm it's actually registered: WP-CLI/PHP shell, `var_dump(wp_get_abilities())`, look for the name. If absent, check `php -l` on `core/mcp-ability-bridge.php` and that it's in the plugin's file-load list in `design-core-elementor.php`. |
| A read ability 403s for an otherwise-working connection | The connected WordPress user's role lacks the underlying `design_core_*` capability (`Design_Core_Elementor_Capabilities`) -- this is independent of the MoSMCP grant and can't be bypassed by granting the ability. |
| A write ability rejects with `design_core_confirmation_required` / `design_core_preview_required` / `design_core_plan_hash_mismatch` / `design_core_page_conflict` | Working as intended -- these are the same Design Core write guards the REST API enforces; the model must supply `confirm:true` and the exact `preview_id`/`plan_hash` from a prior preview call. |
