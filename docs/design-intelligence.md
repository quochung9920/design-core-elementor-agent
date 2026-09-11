# Design Intelligence — UI UX Pro Max integration

Design Core now contains a runtime-local UI/UX knowledge layer normalized from the MIT-licensed [UI UX Pro Max](https://github.com/nextlevelbuilder/ui-ux-pro-max-skill) project.

The architectural rule is deliberate:

```text
UI UX Pro Max upstream data
        |
        | build/sync time only
        v
resources/design-intelligence/catalog.json
        |
        v
Design_Intelligence_Catalog
        |
        v
Design_Advisor
        |
        +--> Design_System_Profile
        |       |- product/category evidence
        |       |- visual direction
        |       |- semantic colors
        |       |- typography
        |       |- spacing/radius
        |       |- page strategy
        |       |- UX rules
        |       `- anti-patterns
        |
        +--> Page Shell -> Design IR -> BuildPlan Preview
        |
        `--> UX Quality Auditor -> Visual QA
```

WordPress does **not** execute UI UX Pro Max's Python search engine, does not install an extra WordPress plugin, and does not call GitHub at runtime. Python exists only as an optional synchronization utility for generating a reviewed JSON bundle.

## Runtime services

- `Design_Core_Elementor_Design_Intelligence_Catalog`: loads and validates the local catalog and deterministically matches a product profile.
- `Design_Core_Elementor_Design_System_Profile`: canonical platform-neutral design-direction contract.
- `Design_Core_Elementor_Design_Advisor`: converts a brief into a profile and can enrich existing Design IR with semantic design tokens/diagnostics without changing business content.
- `Design_Core_Elementor_UX_Quality_Auditor`: performs structural UX checks it can actually prove and explicitly marks contrast/focus/touch-size/overflow/reduced-motion checks as browser/runtime-required instead of fabricating passes.
- `Design_Core_Elementor_Design_Intelligence_Rest_Controller`: additive read/preview-only REST v2 routes.

The starter catalog is intentionally small and includes common Design Core targets such as B2B Service, SaaS, Micro SaaS, E-commerce, Luxury E-commerce, Beauty/Spa/Wellness, and Analytics Dashboard. The B2B profile adds Design Core-specific aliases such as engineering, construction, manpower, recruitment, workforce and industrial so the matching behavior is useful for the project's current websites.

## REST v2

All endpoints use the existing Design Core machine-credential/session permission model. They do not add a new authentication path.

| Route | Scope | Mutation |
|---|---|---:|
| `GET /design-core-elementor/v2/design-intelligence/status` | `design_core_read` | no |
| `POST /design-core-elementor/v2/design-intelligence/recommend` | `design_core_preview` | no |
| `POST /design-core-elementor/v2/design-intelligence/preview` | `design_core_preview` | no |
| `POST /design-core-elementor/v2/design-intelligence/enrich-ir` | `design_core_preview` | no |
| `GET /design-core-elementor/v2/design-intelligence/pages/{id}/ux-audit` | `design_core_read` | no |

`/design-intelligence/preview` performs:

```text
brief
 -> Design Advisor
 -> Design System Profile
 -> recommended existing Page Shell
 -> Design IR
 -> semantic token enrichment
 -> normal BuildPlan Preview
 -> preview_id + plan_hash
```

There are two deliberately different preview modes:

**Exploratory preview (`page_id=0`)** may omit section bindings. Design Core then generates deterministic placeholder bindings only so layout/strategy can be inspected. The response sets `placeholder_bindings=true` and `exploratory_only=true`. That ticket is bound to page `0`, so the normal page-id validation prevents it from ever being executed against a real page.

**Page-bound preview (`page_id>0`)** requires explicit Page Shell content bindings. This prevents placeholder copy from accidentally becoming an approved real-page mutation. If bindings are missing, Design Core fails closed with `design_core_design_bindings_required`.

A real page-bound preview still follows the existing rc21 approval path:

```text
real approved content bindings
 -> page-bound preview_id + plan_hash
 -> explicit user approval
 -> design_core_update_page(confirm:true)
 -> preview/page hash conflict checks
 -> Persistence Service
```

Profiles whose `recommended_shell` is empty fail closed for automatic design preview. A recommendation is still returned by the recommendation endpoint, but Design Core will not pretend an unsuitable Page Shell is safe to compile.

## MCP tools

The bridge now exposes **12 current tools in total**, including two additional read-only Design Intelligence tools:

- `design_core_recommend_design_system`
- `design_core_preview_design_system`

The MCP server remains a thin transport. It does not contain product matching, token selection, page composition, UX scoring, or Elementor mapping logic. Both tools simply validate compact inputs and forward them to Design Core REST v2.

The preferred conversation flow is therefore two-stage before any write:

```text
User: "Design a premium international engineering/manpower landing page"
ChatGPT
 -> design_core_recommend_design_system
 -> Design Core returns B2B profile + tokens + page strategy

User: "Show me an exploratory layout"
ChatGPT
 -> design_core_preview_design_system(page_id=0)
 -> Design Core returns a non-executable exploratory preview

User supplies/approves the actual section content
ChatGPT
 -> design_core_preview_design_system(page_id=42, bindings=approved content)
 -> Design Core returns an executable page-bound preview_id + plan_hash

User: "Apply it"
ChatGPT
 -> design_core_update_page(confirm:true, preview_id, plan_hash)
```

The last step is still governed by the existing remote-write, environment, idempotency, preview conflict, audit and rollback safety boundaries.

## Visual QA integration

`Visual_QA::audit_page()` now includes an additional `ux_quality` dimension. The UX auditor currently verifies structural evidence such as:

- image alternative-text evidence;
- button visible-name evidence;
- heading-order jumps.

Rules that require a rendered browser — contrast, visible focus, target size, horizontal overflow, motion behavior — are returned under `runtime_checks_required`. They do not alter the legacy Visual QA score until Design Core has real runtime evidence for those checks.

This is intentional: the project must not turn a design guideline into a false runtime claim.

## Full upstream synchronization

The bundled starter subset keeps the plugin small. To create a larger catalog from an upstream checkout:

```bash
python3 tools/sync-uiux-promax.py \
  --source-dir ../ui-ux-pro-max-skill \
  --output resources/design-intelligence/catalog.json
```

The sync utility searches the upstream checkout for its canonical data directory and normalizes:

- `products.csv`
- `ui-reasoning.csv`
- `colors.csv`
- `typography.csv`
- `landing.csv`
- `ux-guidelines.csv`

into Design Core's schema. The current upstream project reports 192 product/reasoning/palette profiles and 119 UX guidelines, but the generated count should always be taken from the actual checkout being synchronized rather than assumed.

Review the resulting JSON before committing it. Synchronization is explicit by design; there is no auto-update from GitHub in production.

## Licensing

See `resources/design-intelligence/NOTICE.md`. UI UX Pro Max is MIT licensed. Design Core does not bundle upstream font files or icon binaries; third-party asset/font licenses remain with their respective sources.

## Verification

Contract tests:

```bash
php tests/design-intelligence/run.php
cd mcp-server
npm run check
npm test
```

CI contains the Design Intelligence contract suite and the MCP tool contract tests. GitHub Actions status must still be interpreted honestly: if a workflow run fails at startup with zero jobs, that is not evidence that these tests executed.
