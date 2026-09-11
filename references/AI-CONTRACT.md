# AI Contract

Before building a page, the AI must:

1. Inspect the source HTML, CSS, JS and assets.
2. Read the existing Elementor capability report.
3. Search the Design Core component registry.
4. Detect repeated patterns and extract tokens.
5. Decide between native Elementor, component, loop, custom widget, CSS fallback, or HTML fallback.
6. Build only after a design IR and build plan are formed.
7. Register new reusable assets after implementation.
8. Validate the final output against the source design and responsive breakpoints.

The design must prioritize:

- native Elementor controls
- reuse-first component strategy
- responsive accuracy
- maintainability
- low custom CSS volume

## Input paths (local-only build: no REST, no MCP, no remote API)

HTML source:
1. Dry-run first: `wp design-core preview_html --html='<section>...</section>' --css='...'`
2. Build: `wp design-core convert_html --html='...' --css='...' --title='Page name'`
3. Classes: `Design_Core_Elementor_HTML_Converter` -> `Design_Core_Elementor_Conversion_Service` -> BuildPlan -> Elementor V3/V4 adapter -> `Design_Core_Elementor_Persistence_Service`

Figma source:
1. `wp design-core figma_url <file-url>` (needs server-side Figma token in `Design_Core_Elementor_Figma_Transport`)
2. Or convert a payload file: `wp design-core figma_ir --json=@payload.json`
3. Classes: `Design_Core_Elementor_Figma_Transport` -> `Design_Core_Elementor_Figma_Normalization_Service` -> `Design_Core_Elementor_Figma_Design_IR_Adapter` -> same BuildPlan pipeline as HTML

Page shells (repeatable layouts): `wp design-core shell_compile <shell-name> --bindings='{}'`

## Pipeline order (must not skip)

Design IR v4 (`Design_Core_Elementor_Design_IR` + validator) -> responsive normalization -> Layout Intelligence -> Section Intelligence + fingerprint -> registry match (NEW / REUSE / VARIANT) -> BuildPlan v1 (validator) -> preview/simulator (mutation-free) -> Elementor mapping + Setting Governor -> persistence (save, invalidate, reload, render, verify) -> registry sync + Page Manifest -> Change Ledger (history/rollback, capped at 250 entries)

## Notes for the AI operator

- Everything runs in-process via WP-CLI or wp-admin (HTML Import, Build Preview). There are no HTTP endpoints.
- `wp design-core readiness` reports pipeline health. `wp design-core history` / `rollback <id>` manage persistent history.
- Browser analysis and screenshot QA are optional and degrade gracefully when node/playwright are absent.
- Evidence entries expire after 30 days; freshness is checked at read time.
