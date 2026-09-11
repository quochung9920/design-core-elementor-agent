# Design Core Elementor

**Version:** `1.0.0-rc21` — ChatGPT Remote Control / MCP Bridge Release Candidate

Design Core Elementor is a design-intelligence and compilation layer for turning reference designs, HTML/CSS, Figma files/payloads and reusable section recipes into editable Elementor-native pages while preserving a persistent design system, section/component/widget reuse, runtime control knowledge, page manifests, visual QA and conflict-aware history.

The project is intentionally not a raw HTML-to-`_elementor_data` serializer. Inputs are converted into platform-neutral Design IR, normalized and classified, matched against reusable masters, planned through BuildPlan, simulated before mutation, and only then mapped through governed Elementor adapters.

## Core principles

- Analyze before building.
- Preview and simulate before mutating.
- Section before component when the root owns page-level structure.
- Native before custom; reuse before recreate.
- Runtime controls are authoritative; never guess Elementor controls.
- Exact reuse before variant; variant before duplicate master.
- Content/media belong to instances, not reusable-family identity.
- Controls before CSS; raw HTML is the last fallback.
- Do not guess Elementor private storage formats.
- Save → invalidate → reload → render → verify.
- Visual QA must identify actionable differences, not only produce a score.
- Automatic correction must fail closed when a live safe control is unavailable.
- Quality must be measured against repeatable design benchmarks.

## Canonical pipeline

```text
Tokens             CSS                 Figma
  |                 |                    |
  v                 v                    v
Token Pipeline   CSS AST Service   Figma Normalization
  \                 |                    /
   \________________|___________________/
                    |
HTML / browser evidence / Section Recipe / Page Shell
                    |
                    v
Canonical Design IR v4
        ↓
Responsive normalization
        ↓
Responsive Layout Intelligence v3
        ↓
Section Intelligence + Section Fingerprint v3
        ↓
Section Registry → NEW / REUSE / VARIANT
        ↓
BuildPlan v1
        ↓
BuildPlan Preview v2 + mutation-free execution simulation
        ↓
Elementor V3 mapping → Runtime Setting Governor
        ↓
Elementor V3 / capability-gated V4
        ↓
Persistence Service
save → cache invalidation → reload → render → verify
        ↓
Registry synchronization + Page Manifest v1
        ↓
Page Snapshot
        ↓
Visual Feedback v3
screenshots + rendered DOM + computed styles + Elementor ownership
        ↓
Governed Visual Correction v2
live Control Schema → safe edit → persist → verify → compare again
        ↓
Persistent History / rollback
```

## Reference-informed compilation layers

Design Core now includes original project code informed by a deep review of five public projects. The upstream projects remain references unless explicitly listed as a dependency; Design Core does not copy their business logic into Elementor storage.

- **Style Dictionary** → `Design_Token_Pipeline`: hierarchical/DTCG-like token values, aliases, semantic normalization, cycle detection and CSS-variable export in PHP. No Node runtime is required.
- **MyIntervals/PHP-CSS-Parser** → `CSS_AST_Service`: `sabberworm/php-css-parser` is an MIT Composer dependency when `vendor/` is installed; a bounded Design Core scanner remains as a source-checkout fallback.
- **WordPress/mcp-adapter** → `WordPress_MCP_Compatibility`: optional official WordPress Abilities/MCP interoperability. Only explicit read/preview Design Core abilities are marked public; write tools remain on the rc21 approval-gated path.
- **msrbuilds/elementor-mcp** → `Elementor_Setting_Governor`: practical Elementor grid/dimension safeguards are reimplemented behind the live `Control_Schema_Registry`; no upstream control list is treated as runtime truth.
- **bernaferrari/FigmaToCode** → `Figma_Normalization_Service`: normalize visibility, stacking, absolute-position evidence, variable references and target-fidelity warnings before Design IR conversion.

See [`docs/reference-integration-5-projects.md`](docs/reference-integration-5-projects.md) and [`resources/reference-integrations/NOTICE.md`](resources/reference-integrations/NOTICE.md) for the reviewed upstream commits, licensing boundaries and exact implementation decisions.

## rc20 fidelity layer

### BuildPlan Preview v2

`Design_Core_Elementor_Build_Plan_Preview` remains mutation-free but now delegates output estimation to `Design_Core_Elementor_Build_Plan_Simulator` instead of equating one Design IR node with one Elementor element.

The simulator understands strategy shape:

- native/custom/loop widgets can collapse a whole IR subtree into one Elementor widget;
- persisted component/section blueprints can provide a higher-confidence element count;
- native composition falls back to IR-shape estimation when no executable blueprint is available;
- predicted container/widget counts and widget-type frequencies are reported with per-item confidence/reasons.

A preview therefore reports planning decisions plus the likely Elementor surface without executing a strategy or writing a post/registry.

### Responsive Layout Intelligence v3

Layout Intelligence now treats a design as responsive states rather than a single desktop snapshot. Each node can report desktop/tablet/mobile and available extended states, including transitions such as:

```text
desktop: split 40/60
mobile:  stack
transition: desktop->mobile:split->stack
```

Patterns remain Elementor-neutral: full-bleed/inner, split, grid, stack, overlap, flow and leaf. Browser geometry is selected from the closest captured viewport for each state rather than always using the largest viewport.

### Visual Feedback v3

The visual layer still treats screenshot similarity as visual evidence, but it can now automatically collect rendered-target evidence with Playwright when available.

`browser-analyze-target.mjs` captures, per governed viewport:

- DOM path and text evidence;
- geometry;
- layout/spacing computed styles;
- typography metrics;
- image/background fit and position;
- border radius;
- the nearest owning Elementor `data-id`, element type and widget type.

Reference and candidate elements are matched conservatively by DOM path, HTML id, text+tag, text or tag/index. The resulting correction directives contain exact reference/candidate values and the owning Elementor element ID when known.

Remote rendered analysis is network-constrained to the target origin plus explicitly allowed hosts; local files have HTTP(S) traffic blocked.

### Governed Visual Correction v2

`Design_Core_Elementor_Visual_Correction_Applier` closes the rc19 feedback-only gap for high-confidence V3 cases. It:

1. loads and validates the current Elementor V3 tree;
2. addresses a concrete element ID discovered during rendered analysis;
3. resolves the requested control through the live `Control_Schema_Registry`;
4. refuses unknown, ambiguous or non-responsive controls;
5. maps CSS target values into the live Elementor control shape;
6. saves through `Design_Core_Elementor_Persistence_Service`;
7. verifies reload/render/storage and records History;
8. lets `Visual_Correction_Service` compare again on the next iteration.

Initial governed correction coverage includes width/max-width/height/min-height, gap, padding/margin, flex alignment, typography size/line-height/letter-spacing/weight/alignment, image object-fit/position, background size/position and simple border radius.

V4/Atomic correction remains fail-closed until a public governed mutation contract exists.

### Figma Transport + Figma Intelligence v3

`Design_Core_Elementor_Figma_Transport` is the separate authentication/network boundary. It can parse normal Figma design/file/proto/board URLs, fetch a file or selected node, resolve image-fill references and request node exports.

Credentials are not stored by the transport. Configure them through:

```php
define( 'DESIGN_CORE_FIGMA_ACCESS_TOKEN', '...' );
```

or the `design_core_elementor_figma_access_token` filter.

`Design_Core_Elementor_Figma_Normalization_Service` now runs before the adapter. It filters hidden nodes, makes authored absolute positioning explicit, preserves stacking evidence, collects bound-variable references (including gradient-stop bindings), and emits warnings for source features without a guaranteed Elementor equivalent.

`Design_Core_Elementor_Figma_Design_IR_Adapter` v3 remains platform-neutral and preserves:

- Auto Layout direction/wrap/gaps/alignment;
- horizontal/vertical sizing modes;
- min/max/fixed dimensions;
- constraints;
- component instance identity and component properties;
- bound-variable metadata plus normalized variable-reference evidence;
- fills, strokes, effects, per-corner radii, rotation and blend mode evidence;
- text style-run evidence;
- prototype reactions;
- resolved image-fill URLs when supplied by transport;
- normalization warnings/diagnostics rather than silent target approximation.

Transport/auth never become part of Elementor adapters or Design IR storage contracts.

### Fidelity benchmark corpus

`Design_Core_Elementor_Design_Benchmark_Corpus` introduces repeatable quality measurement. rc20 starts with:

- responsive foundation;
- marketing composition;
- 40/60 hero split;
- responsive card grid;
- overlapping media composition.

The corpus can run mutation-free planning checks today and can compare generated targets to fixed references through Visual Feedback. It is intentionally designed to grow into a larger real-design benchmark set instead of treating code volume as evidence of quality.

### Elementor Persistence Service

`Design_Core_Elementor_Persistence_Service` remains the governed V3 persistence boundary:

1. snapshot the previous Elementor payload;
2. write through the native Elementor Document API;
3. invalidate element/CSS caches;
4. reload the stored tree;
5. render the frontend;
6. verify stored JSON and hashes;
7. record runtime evidence and persistent history after a verified mutation.

Elementor V4 remains capability-gated and never fabricates Atomic private storage.

### Widget Intelligence v2

Elementor runtime data is the source of truth. `Design_Core_Elementor_Control_Schema_Registry` merges normal, optimized style and common controls, generates deterministic fingerprints/capability profiles and supplies machine-readable JSON Schema. Widget Inspector, native-widget verification, preview, correction and agent clients all share this live authority.

`Design_Core_Elementor_Elementor_Setting_Governor` adds a post-mapping safety pass for runtime-confirmed edge cases such as classic grid row initialization. It never writes a setting absent from the active Elementor control schema.

### Section Recipes, Page Shells, Snapshot and History

The rc19 design-system/control-plane work remains active:

- Section Recipe Library for reusable platform-neutral section patterns;
- Page Shells for common page families;
- Section Registry + Page Manifest for reuse decisions;
- Page Snapshot for one normalized page digest;
- Section Explainability for auditable match decisions;
- Change Ledger for post-commit, conflict-aware V3 rollback.

## Agent / Abilities bridge v2

The legacy compact gateway registers three WordPress Abilities when the public Abilities API exists:

```text
design-core/list-tools
design-core/get-tool-schema
design-core/call-tool
```

They remain private by the WordPress MCP Adapter's default ability policy unless explicitly exposed by a host configuration.

In addition, `Design_Core_Elementor_WordPress_MCP_Compatibility` registers a separate, deliberately public **read/preview-only** set for the official WordPress MCP Adapter:

```text
design-core/site-status
design-core/page-snapshot
design-core/build-preview
design-core/design-system-recommend
design-core/history-list
```

No generic write/rollback/publish ability is marked public. Governed mutation remains on Design Core Remote API v2 / the explicit Node MCP bridge where preview IDs, plan hashes, confirmation, scopes, environment guards, idempotency, audit and rollback stay enforceable.

REST, CLI, admin and Abilities continue to call the same core services rather than implementing separate behavior.

## rc21 ChatGPT Remote Control / MCP Bridge

A separate namespace, `design-core-elementor/v2`, and a standalone Node/TypeScript service (`mcp-server/`) expose the same underlying services to ChatGPT over the Model Context Protocol, without giving ChatGPT direct WordPress/database access and without duplicating any Design IR/BuildPlan/Elementor/persistence logic in the bridge:

- scoped machine credentials (`design_core_read/preview/build/modify/publish/rollback`), hashed tokens, revoke/rotate, rate-limited;
- a mandatory READ → PREVIEW → APPROVAL → EXECUTE → VERIFY workflow: `/build/preview` and `/figma/preview` return a `preview_id` + `plan_hash` that `/pages/{id}/update` must present unchanged, with the page's live hash re-checked at execute time so a changed page is refused as a conflict, never silently overwritten;
- `Idempotency-Key` support on every write route, with the MCP Bridge deriving a safe default automatically;
- a global "Disable Remote Writes" switch (Design Core → Remote Access) that blocks writes/destructive routes while leaving reads untouched;
- twelve distinct MCP tools after the Design Intelligence addition, with real `readOnlyHint`/`destructiveHint` annotations rather than one generic dispatcher.

See [`docs/rc21-chatgpt-mcp.md`](docs/rc21-chatgpt-mcp.md) for the architecture, [`docs/design-intelligence.md`](docs/design-intelligence.md) for the local UI/UX knowledge layer, and [`docs/mcp-local-wsl-docker.md`](docs/mcp-local-wsl-docker.md) for Windows/WSL2/Docker setup end to end.

## REST API

Namespace: `design-core-elementor/v1` (unchanged; `v2` above is additive, for the MCP Bridge).

Important intelligence/fidelity routes include:

- `GET /elementor-widgets`
- `GET /elementor-widgets/{widget}`
- `GET /elementor-widget-candidates`
- `POST /build-preview`
- `GET /page-snapshot/{id}`
- `POST /figma/design-ir` — accepts supplied Figma JSON or `figma_url`
- `POST /visual-feedback`
- `POST /visual-correction` — requires `confirm=true`
- `GET /benchmarks`
- `GET /benchmarks/{id}/planning`
- `GET /history`
- `POST /history/{entry}/rollback`
- `POST /page-shell/compile`
- `GET|POST /section-explain`
- `GET /agent/tools`
- `GET /agent/tools/{tool}/schema`
- `POST /agent/execute`

All Design Core management routes require administrator capability and bounded payloads.

## WP-CLI examples

```bash
wp design-core readiness
wp design-core page_snapshot 42
wp design-core preview_html --html='<section>...</section>' --css='...'
wp design-core figma_ir design.json --node='123:456'
wp design-core figma_url 'https://www.figma.com/design/...?...'
wp design-core browser_target 'https://example.test/page'
wp design-core visual_feedback <reference> <candidate> --page-id=42
wp design-core visual_correct <reference> <candidate> --page-id=42 --confirm=1
wp design-core benchmarks
wp design-core benchmarks hero-split
wp design-core history
wp design-core rollback <entry-id>
wp design-core agent_tools
```

## Admin UI

Design Core admin includes full-width screens for:

- Dashboard
- HTML Import
- Build Preview v2 — HTML/CSS, Figma URL, Figma JSON or Page Shell bindings
- Elementor Widgets / Widget Intelligence
- Page Intelligence — Snapshot, Visual Feedback v3 and governed automatic correction
- Quality Benchmarks
- Registry + section explainability
- Persistent History
- Agent Bridge v2
- Remote Access — MCP/remote API status, the write kill switch, machine credential lifecycle
- Design Intelligence — local UI/UX recommendation/profile preview
- Settings
- Production Readiness

## Readiness semantics

`Design_Core_Elementor_Production_Readiness` distinguishes architecture checks from runtime proof. Architecture checks cover Preview v2/Simulator, Layout Intelligence v3, Visual Feedback v3, Visual Correction v2, Figma Transport/Normalization/Adapter v3, Token Pipeline v1, CSS AST v1, Elementor Setting Governor v1, Benchmark Corpus v1 and Agent Gateway v2. Official WordPress MCP Adapter availability and the external Sabberworm parser are reported separately as optional runtime integrations.

When the runtime is V3 and browser tooling is available, a fresh `visual-correction-roundtrip` evidence record is also required before `production-ready` can be reported.

Architecture presence alone is never treated as production evidence. Missing browser tooling, missing optional Figma credentials, skipped Pro gates or GitHub Actions startup failures are not equivalent to a pass.

## Supported baseline

- WordPress 6.5+
- PHP 8.1+
- Elementor required
- Elementor Pro optional and capability-gated
- Figma access token optional and externally configured
- WordPress Abilities API / official MCP Adapter optional and feature-detected
- Composer dependency install recommended for standards-aware CSS normalization; source checkout has a bounded fallback

## Development direction after rc21

- verify the MCP Bridge against a real ChatGPT connector session over a real secure tunnel (only local/scripted MCP clients have exercised it so far);
- add a real embedded OAuth 2.1 + PKCE + Dynamic Client Registration authorization server to the bridge once that can be tested against a live ChatGPT handshake, per the MCP Authorization spec;
- extend `execute_approved_plan()`'s reuse fidelity (it deliberately skips `apply_registry_reuse()`/asset import today, trading some component-reuse quality for exact preview/plan_hash determinism) once a design exists for keeping both properties at once;
- expand reference-integration runtime evidence only after disposable-page tests prove token/CSS/Figma/Elementor behavior on real WordPress + Elementor versions;
- grow Figma target-fidelity handling only through explicit capability checks and rendered comparison rather than static approximation.

## Development direction after rc20

rc20 deliberately prioritizes fidelity and proof over adding another broad architectural layer. Remaining work should focus on evidence:

- execute/stabilize the complete runtime and visual gate matrix on real WordPress/Elementor versions;
- grow the benchmark corpus from the initial patterns to dozens of real designs;
- broaden visual-correction coverage only when live control contracts are unambiguous;
- resolve/import temporary Figma CDN assets into the WordPress Media Library through the existing governed asset boundary;
- improve cross-markup element/section alignment for visual analysis;
- migrate remaining reusable page-specific provisioning knowledge into recipes/shells;
- extend V4/Atomic and Pro Loop only through verified public integrations;
- profile registry/history/snapshot scale before introducing dedicated database tables.

See [`docs/rc21-chatgpt-mcp.md`](docs/rc21-chatgpt-mcp.md), [`docs/design-intelligence.md`](docs/design-intelligence.md), [`docs/reference-integration-5-projects.md`](docs/reference-integration-5-projects.md), [`docs/mcp-local-wsl-docker.md`](docs/mcp-local-wsl-docker.md), and [`docs/rc20-fidelity.md`](docs/rc20-fidelity.md).
