# Design Core Elementor 1.0.0-rc21 next steps

rc21 is the ChatGPT Remote Control / MCP Bridge release. It exposes the existing rc20 pipeline to ChatGPT through a new Remote API v2 namespace and a standalone Node/TypeScript MCP Bridge service, without duplicating any Design IR/BuildPlan/Elementor/persistence logic in the bridge and without giving ChatGPT direct WordPress/database access. See [`docs/rc21-chatgpt-mcp.md`](docs/rc21-chatgpt-mcp.md).

## Implemented through rc21

- `design-core-elementor/v2` REST namespace composing existing services (`v1` untouched); the one genuinely new piece of business logic is `Conversion_Service::execute_approved_plan()`, which updates an existing page from an already-approved, hash-locked plan (the prior pipeline only ever created new pages).
- Scoped capabilities (`design_core_read/preview/build/modify/publish/rollback`), versioned/idempotent-migrated onto `administrator`.
- Machine credentials: hashed tokens, scopes, environment, revoke/rotate, per-credential rate limiting; fixed two real deployment gaps found by testing against a live Apache/mod_php WordPress install (Authorization header not reaching `$_SERVER`, and Elementor's Document API needing a real acting WordPress user).
- Preview approval (`preview_id`/`plan_hash`/`current_page_hash`, transient-backed, single-use) and `Idempotency-Key` support on every write route.
- A global remote-write kill switch and environment tracking (Design Core → Remote Access), plus machine-credential lifecycle UI.
- A standalone MCP Bridge (`mcp-server/`) with ten distinct, safety-annotated tools, site binding that never accepts a raw URL from the model, and an automatically-derived default idempotency key.
- Docker Compose example stack for local WSL2 development (`docker-compose.yml`, `.env.example`).
- Along the way: fixed a Figma-adapter/Design-IR-validator interaction that rejected every ordinary fixed-width Figma root frame, and a Layout Intelligence heuristic that could misreport a mobile stack as a horizontal split -- both pre-existing rc20 gaps never caught because CI has never actually run on this repository (see "CI caveat" in `docs/rc21-chatgpt-mcp.md`).

## rc21 next priorities

1. Verify the MCP Bridge against a real ChatGPT connector session over a real secure tunnel -- only local/scripted MCP clients (unit tests plus a live manual pass) have exercised it so far.
2. Add a real embedded OAuth 2.1 + PKCE + Dynamic Client Registration authorization server to the bridge, per the MCP Authorization spec, once it can be tested against a live ChatGPT handshake rather than shipped unverified.
3. Reconcile `execute_approved_plan()`'s determinism-over-reuse-fidelity tradeoff (it deliberately skips `apply_registry_reuse()`/asset import to keep execution identical to what was previewed and hashed) if a design emerges for keeping both properties at once.
4. Exercise `Remote_Write_Guard::ensure_production_guard()` and multi-site (`local`/`staging`/`production`) binding against a real staging/production deployment before relying on it alone for a real production site.
5. Investigate the pre-existing, unrelated `tests/architecture/run.php` failure about `track_registry_mutation()` vs. atomic registry mutation -- found while auditing for rc21, deliberately left flagged rather than fixed since it touches `Conversion_Transaction` rollback semantics repo-wide.

## Implemented through rc20

- Design IR v4, BuildPlan v1, Section Registry/Fingerprint v3 and Page Manifest v1 remain the canonical design/reuse contracts.
- Widget Intelligence v2 remains the shared live Elementor control authority.
- BuildPlan Preview v2 predicts the Elementor surface through a mutation-free strategy simulator instead of counting IR nodes.
- Responsive Layout Intelligence v3 models layout patterns per state and records split/grid/stack/etc. transitions.
- Visual Feedback v3 automatically collects rendered DOM/computed-style evidence when Playwright is available and preserves owning Elementor IDs.
- Visual Correction v2 applies only addressable, runtime-verified V3 controls, persists through the governed service and fails closed otherwise.
- Browser/screenshot tooling now uses constrained network policies for rendered targets.
- Figma Transport parses URLs, reads files/nodes, resolves image fills and stays separate from the Figma → Design IR adapter.
- Figma Adapter v2 preserves sizing, constraints, component/variable/style evidence, reactions and resolved image URLs.
- Fidelity Benchmark Corpus v1 provides fixed planning/visual reference cases and a service shared by admin, REST, CLI and agents.
- Admin adds Figma URL preview, governed automatic correction and Quality Benchmarks.
- Agent Gateway v2 exposes `visual-correct`, `quality-benchmarks` and Figma URL input while keeping the compact three-ability surface.
- Production Readiness recognizes rc20 architecture and requires fresh visual-correction runtime evidence when applicable.

## rc20 next priorities (still open, unrelated to rc21)

### 1. Execute and stabilize the complete rc20 runtime matrix

This is the highest priority. Run PHP 8.1/8.2/8.3 contracts, WordPress 6.5 baseline, latest WordPress + Elementor, browser tooling, generated-page visual QA and optional Elementor Pro gates. Resolve real compatibility findings before calling rc20 production-ready.

The repository currently has a history of GitHub Actions `startup_failure` runs with zero jobs created. That infrastructure problem must be resolved or an equivalent trusted runtime environment must execute the gates. A workflow file existing in the repository is not runtime proof.

### 2. Grow the benchmark corpus from patterns to real designs

The initial five benchmarks establish the contract. Expand toward 30–100 representative references:

- multiple hero families;
- card/grid/list compositions;
- pricing/comparison/FAQ/timeline;
- headers, navigation, mega menus and footers;
- article/archive/commerce layouts;
- overlapping/absolute compositions;
- difficult mobile reflow/reordering;
- real Figma frames/components/variables.

Each benchmark should track expected planning strategy, expected Elementor architecture and per-viewport similarity thresholds.

### 3. Broaden visual-correction coverage conservatively

Only add automatic mappings that can be proven from the live runtime schema. Candidate next groups:

- more container sizing/grow/shrink/flex-basis controls;
- background color/gradient when an exact native control is known;
- border width/style/color and simple box shadow;
- responsive ordering/wrapping;
- widget-specific image/content sizing.

Never convert an ambiguous visual difference into an unverified private control write.

### 4. Improve cross-markup visual alignment

Reference HTML/Figma exports and Elementor markup often have different wrapper trees. Improve section/element matching using:

- text/semantic anchors;
- image/aspect-ratio evidence;
- relative geometry neighborhoods;
- section boundaries and Page Manifest mappings;
- widget semantic role.

Do not use AI/heuristic matching as authority for writes without a concrete Elementor element and live control contract.

### 5. Finish governed Figma asset import

Figma Transport can resolve temporary image-fill URLs. Add an asset resolver/import stage that downloads allowed Figma assets into the WordPress Media Library, fingerprints/deduplicates them and replaces temporary CDN references before persistence. Keep this in the asset boundary rather than inside Design IR or the Figma adapter.

### 6. Complete migration from page-specific provisioning to recipes/shells

Keep `scripts/provision-air-*` as regression fixtures while moving reusable production knowledge into Section Recipe Library and Page Shell definitions. New page work should prefer recipe + bindings rather than new page-specific Elementor structure scripts.

### 7. Compatibility corpus for Elementor V4 / Pro

Continue capability-gated behavior. Test real Elementor/Pro versions and capture verified public contracts for Atomic composition, global classes and Loop behavior. Do not treat reverse-engineered private storage as a supported implementation contract.

### 8. Performance profiling before new storage architecture

Profile Page Snapshot, Widget Intelligence, registries and Change Ledger on realistically large sites. Add fingerprint-keyed short-lived caches or indexed tables only when measurement shows an actual bottleneck; all migrations need versioning, backup and rollback.

## Release discipline

`production-ready` means fresh applicable runtime evidence passed on the target release environment. Startup failures, skipped optional gates, missing Playwright/Figma credentials or unavailable V4 abilities are environmental/capability states, not passing evidence.
