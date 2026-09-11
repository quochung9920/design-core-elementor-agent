# Five-project reference integration

Reviewed: 2026-09-05

This integration deliberately borrows **architecture and verified behavior**, not foreign business logic. Design Core remains the single engine that owns Design IR, BuildPlan, Elementor mapping, persistence, QA, approval, audit and rollback.

Reviewed upstream snapshots:

| Project | Reviewed ref | License | Design Core use |
|---|---|---|---|
| `style-dictionary/style-dictionary` | `863685dce3ef178f15040d45be02d2a829ee49a8` | Apache-2.0 | architecture/concepts only |
| `MyIntervals/PHP-CSS-Parser` | `7df1210647fde459b0621c05a0bbbf7054aa0c14` | MIT | optional Composer dependency (`sabberworm/php-css-parser`) |
| `WordPress/mcp-adapter` | `653e0cbe77fdbff62866d6d445f0a09e6ffc4cb3` | GPL-2.0-or-later | optional compatibility target; no bundled source |
| `msrbuilds/elementor-mcp` | `29e3fdddcbce288ba91cde09d233faa94adcd947` | GPL-2.0 | behavioral/runtime lessons only; no bundled source |
| `bernaferrari/FigmaToCode` | `f5c4831d5de6cffc19a73fe2823c56b4bb551281` | GPL-3.0 | compiler/normalization concepts only; no bundled source |

## Resulting architecture

```text
Design brief / Figma / HTML+CSS / tokens
        |
        +--> Design Token Pipeline
        |      hierarchy -> aliases -> semantic normalization
        |
        +--> CSS AST Service
        |      parse -> normalize -> media/static rule model
        |
        +--> Figma Normalization
        |      visibility -> stacking -> absolute evidence -> variable refs -> warnings
        |
        v
Design IR v4
        |
        v
BuildPlan
        |
        v
Runtime Control Schema
        |
        +--> Elementor Setting Governor
        |
        v
Elementor V3/V4 adapter
        |
        v
Persistence / reload / render / QA / history

Optional WordPress Abilities API
        |
        +--> Official WordPress MCP Adapter
        |      safe Design Core read/preview abilities only
        |
        `--> existing Design Core Node MCP bridge
               explicit read + governed write tools
```

The important boundary is unchanged:

```text
AI / MCP does not author private Elementor storage.
MCP -> Design Core -> validated plan -> runtime controls -> persistence.
```

## 1. Style Dictionary -> Design Token Pipeline

### What was useful upstream

Style Dictionary treats design tokens as a source graph rather than a flat color list: hierarchical names, aliases/references, transforms and target formats are separate concerns. That model fits Design Core better than storing unrelated color/font/spacing arrays.

### What Design Core implements

`core/design-token-pipeline.php` provides an original PHP-native compiler:

- hierarchical token groups;
- DTCG-style `$value` and `$type` support;
- inherited token types;
- exact aliases such as `{color.brand}`;
- scalar interpolation;
- cycle detection;
- bounded depth/token count;
- semantic normalization into `colors`, `typography`, `spacing`, `radius`;
- deterministic CSS custom-property export;
- no implicit unit conversion.

`Design_Core_Elementor_Design_Token_Service::normalize()` now accepts both the existing Design Core token shape and a hierarchical dictionary.

Semantic path beats generic data type. A token under `radius.*` with `$type: dimension` stays radius rather than being guessed as spacing. Unknown generic dimension tokens are preserved in the compiled dictionary with a warning instead of being silently assigned to a Design Core category.

### What was not adopted

- no Node.js Style Dictionary runtime in WordPress;
- no upstream code bundled;
- no mobile-platform exporters that Design Core does not need;
- no dynamic arbitrary transform plug-ins at runtime.

## 2. PHP-CSS-Parser -> CSS AST / rule normalization

### What was useful upstream

The Sabberworm parser demonstrates why CSS should be parsed structurally instead of relying on regular expressions for nested functions, selectors, at-rules and escaped values.

### What Design Core implements

`core/css-ast-service.php` defines a bounded normalized rule contract used by:

- `Responsive_Style_Parser`;
- `Css_Auditor`;
- future static analysis consumers.

When Composer dependencies are present, the service first parses and normalizes source CSS through `Sabberworm\CSS\Parser`. The normalized source is then converted into Design Core's small rule model. When `vendor/` is absent, a quote/parenthesis/bracket-aware bounded scanner remains available as a fail-safe.

The browser computed-style analyzer is still authoritative for exact cascade/state behavior. Static selector matching intentionally does **not** claim to implement the full browser cascade.

### Dependency

```bash
composer install
```

`composer.json` requires:

```text
sabberworm/php-css-parser ^9.4
```

The plugin checks for `vendor/autoload.php`; a source checkout without vendor remains functional through the bounded fallback.

## 3. WordPress MCP Adapter -> official Abilities compatibility

### What was useful upstream

The official adapter establishes a clean boundary:

```text
WordPress Abilities API
        -> MCP Adapter
        -> MCP tool/resource/prompt
```

Abilities are private by default. MCP exposure should be intentional, and both transport permission and individual ability permission matter.

### What Design Core implements

`core/wordpress-mcp-compatibility.php` registers a deliberately small public MCP-compatible ability set when the WordPress Abilities API exists:

- `design-core/site-status`
- `design-core/page-snapshot`
- `design-core/build-preview`
- `design-core/design-system-recommend`
- `design-core/history-list`

These abilities use `meta.mcp.public=true`, remain read/preview-only and retain Design Core capability callbacks.

Writes are intentionally **not** exposed as public generic abilities. Existing rc21 write boundaries stay canonical:

```text
preview
 -> preview_id + plan_hash
 -> explicit confirmation
 -> scoped machine credential
 -> environment/write guard
 -> idempotency
 -> Design Core persistence
 -> audit/history
 -> rollback
```

The existing Node MCP bridge is therefore not replaced. The official adapter is an optional interoperability path for safe WordPress-native abilities.

### What was not adopted

- no generic `call_any_ability` write surface;
- no bypass around Machine Credentials or Remote API v2;
- no automatic public exposure of Design Core mutation operations.

## 4. Elementor MCP -> runtime Elementor setting governance

### What was useful upstream

The Elementor-oriented project contains several practical behaviors worth defending against:

- Elementor controls must be capability/runtime verified;
- newly authored classic grid containers can need an explicit single-row grid seed rather than relying on defaults;
- partially populated dimension controls can fail to emit expected CSS;
- snapshots/change history should exist around mutation;
- writes should be bounded and safe-by-default.

Design Core already had stronger persistence/history/preview governance, so those systems were not replaced.

### What Design Core implements

`core/elementor-setting-governor.php` is an original runtime-aware guard layered after V3 IR mapping.

For grid IR, it queries `Control_Schema_Registry` before emitting **any** setting:

- `container_type=grid` only if runtime advertises it;
- `grid_columns_grid` only if runtime advertises it;
- `grid_rows_grid = 1fr` for a newly authored single-row grid only if runtime advertises it;
- `grid_gaps` only if runtime advertises it.

If the active Elementor version does not expose those controls, Design Core warns/fails through normal mapping governance rather than inventing private storage keys.

The governor also detects incomplete dimension structures and reports the risk. It does not mutate externally supplied partial dimension controls just to make them look valid.

`Elementor_V3_Adapter::normalize()` now performs:

```text
Design IR
 -> Mapping Engine
 -> Elementor Setting Governor
 -> validated Elementor elements
```

### What was not adopted

- no direct DB/meta write pattern;
- no generic Elementor mutation endpoint;
- no copy of upstream control lists as truth;
- no replacement of Design Core Persistence Service/Change Ledger.

## 5. FigmaToCode -> compiler-style Figma normalization

### What was useful upstream

A robust Figma conversion pipeline benefits from separating source normalization from target generation. Important source semantics include visibility, auto-layout, absolute children, stacking order and variable references. Target limitations should produce warnings rather than invisible approximations.

### What Design Core implements

`core/figma-normalization-service.php` runs before Design IR conversion and adds source evidence for:

- filtering `visible=false` children;
- explicit `layoutPositioning=ABSOLUTE` evidence;
- absolute `left/top` relative to the parent bounding box;
- authored `itemReverseZIndex` handling;
- bound-variable references from node/fill/stroke/effect data;
- bound variables on gradient stops;
- warnings for vector geometry, target-dependent effects and unsupported gradient categories;
- bounded node/depth limits.

`Figma_Design_IR_Adapter` is now v3 and includes the normalization report plus per-node variable refs/warnings in diagnostics/source evidence.

The normalizer does not execute Figma code and does not generate Elementor JSON directly:

```text
Figma Transport
 -> Figma Normalization
 -> Design IR
 -> BuildPlan
 -> runtime-verified Elementor mapping
```

### What was not adopted

- no FigmaToCode renderer/runtime;
- no React/Tailwind/Flutter code-generator dependency;
- no GPL source copied into the plugin;
- no silent conversion of target-incompatible effects.

## Safety and licensing boundary

Only `sabberworm/php-css-parser` is a direct upstream runtime dependency, under MIT. The other four projects are used as documented architectural/behavioral references; their source is not vendored or copied.

This distinction is intentional because two references are GPL-family projects and Design Core should not accidentally create a derivative source bundle merely to reuse an implementation pattern.

See `resources/reference-integrations/NOTICE.md` for provenance.

## Verification

Standalone contracts:

```bash
composer install
php tests/reference-integration/run.php
```

Existing contracts should still run:

```bash
php tests/architecture/run.php
php tests/registry/run.php
php tests/settings/run.php
php tests/rc19-intelligence/run.php
php tests/rc20-fidelity/run.php
php tests/remote-api-v2/run.php
php tests/design-intelligence/run.php
```

MCP bridge:

```bash
cd mcp-server
npm ci
npm run check
npm test
npm run build
```

Real WordPress/Elementor acceptance still requires a disposable page and the normal sequence:

```text
snapshot
 -> preview
 -> update
 -> reload/render
 -> visual/UX verify
 -> history
 -> rollback
 -> verify restored
```

Architecture/source presence is not runtime proof. If GitHub Actions reports `startup_failure` with zero jobs, record it as **CI not executed**, not as a passed or failed code suite.
