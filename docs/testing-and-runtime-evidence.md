# Testing and Runtime Evidence

The project separates fast standalone contract tests, WordPress/Elementor runtime smoke tests, browser tooling tests, and environment-scoped runtime evidence.

## Why the distinction matters

A passing PHP unit-style suite proves domain behavior in its test harness. It does not prove that the active Elementor runtime can save, reload, render, generate CSS, or execute proprietary/public editor APIs.

Likewise, capability detection proves an API or class is present; it does not prove Design Core used it successfully.

## Standalone PHP suites

Standalone suites use `tests/bootstrap-standalone.php` and run without a complete WordPress installation.

Current focused suites:

| Suite | Main coverage |
|---|---|
| `tests/architecture/run.php` | canonical pipeline, contracts, strategy/fallback architecture |
| `tests/registry/run.php` | RegistryItem validation, persistence, import, rollback |
| `tests/settings/run.php` | settings defaults and sanitization |
| `tests/control-mapping/run.php` | runtime-schema-aware control mapping |
| `tests/control-adapters/run.php` | widget-specific adapters and value encoding |
| `tests/scoped-css/run.php` | scoped CSS allow/reject policy |
| `tests/v3-adapter/run.php` | V3 element mapping and lifecycle contracts |
| `tests/native-mapper-integration/run.php` | native mapping integration |
| `tests/native-style-analysis/run.php` | CSS declaration to canonical style mapping |
| `tests/background-asset-discovery/run.php` | background media discovery/import continuity |
| `tests/global-style-bridge/run.php` | additive Kit globals and references |
| `tests/global-layout-standard/run.php` | global container tiers and managed CSS |
| `tests/gap-first-spacing/run.php` | parent Gap ownership and margin governance |
| `tests/layout-media-analysis/run.php` | layout/width/height/media policy and adversarial cases |
| `tests/analysis-complexity/run.php` | source complexity and browser-analysis bounds |
| `tests/why-section-blueprint/run.php` | control-first Why section blueprint |
| `tests/air-compare-section/run.php` | desktop/mobile comparison section blueprint |

Run one suite:

```bash
php tests/layout-media-analysis/run.php
```

Run all focused suites:

```bash
set -e
for suite in tests/*/run.php; do
  echo "==> $suite"
  php "$suite"
done
```

## WordPress + Elementor runtime suites

These must run inside a real WordPress installation with Elementor active, normally with `wp eval-file`:

| File | Evidence/gate |
|---|---|
| `tests/production-smoke.php` | conversion lifecycle, security, migration, broad runtime behavior |
| `tests/integration/page-reuse-smoke.php` | Page A → registry → Page B master reuse |
| `tests/custom-widget-multi-instance-smoke.php` | runtime widget registration and multiple instances |
| `tests/global-design-system-smoke.php` | Kit sync, references, no duplicate on resync |
| `tests/planner-strategy-matrix-smoke.php` | planner strategy selection in live environment |
| `tests/native-widget-smoke.php` | functional native widget selection/binding/roundtrip |
| `tests/multi-section-fallback-smoke.php` | one-root fallback preserves other sections |
| `tests/loop-strategy-smoke.php` | loop capability/integration/fallback behavior |
| `tests/atomic-runtime-smoke.php` | public Atomic capability and governed roundtrip |
| `tests/global-style-sync-runtime.php` | focused live global sync and generated CSS checks |

Example:

```bash
wp eval-file wp-content/plugins/design-core-elementor/tests/production-smoke.php
```

`wp eval-file` wraps the file. Runtime test files intended for that command must not put `declare(strict_types=1);` at top level.

## Native mapping runtime smoke

`scripts/smoke-native-control-mapping.php` verifies the control-first section path in a live Elementor site. It is intended to check:

- runtime control discovery;
- widget-specific mappings;
- Document API save;
- stored-data read-back;
- reload and render;
- generated page CSS;
- architecture audit.

Run it using the target environment's WP-CLI conventions and review any target IDs/prerequisites in the script first.

## JavaScript and browser checks

Install exact dependencies:

```bash
npm ci
npx playwright install chromium
```

Syntax check all browser scripts:

```bash
npm run check
```

Computed-style fixture:

```bash
node scripts/browser-analyze.mjs tests/fixtures/responsive.html 1440,1024,768,390 > /tmp/browser.json
```

Capture and compare:

```bash
node scripts/capture-page.mjs tests/fixtures/marketing.html 390 /tmp/reference.png
node scripts/capture-page.mjs https://example.test/?page_id=42 390 /tmp/candidate.png
node scripts/visual-compare.mjs /tmp/reference.png /tmp/candidate.png
```

## PHP lint matrix

The supported matrix is PHP 8.1, 8.2, and 8.3. CI runs:

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
```

Do not hide lint exit codes behind a pipeline whose final command always succeeds.

## GitHub Actions

`.github/workflows/ci.yml` defines:

### `php-lint`

Matrix: PHP 8.1, 8.2, 8.3.

Runs PHP syntax, architecture contracts, registry validation/rollback, and settings regressions.

### `browser-qa`

Uses Node 20 and Chromium. Runs JS syntax, browser computed-style fixture, screenshot capture, and diff-tool smoke.

The fixture-vs-itself comparison validates tooling only.

### `wordpress-elementor`

Installs current WordPress and Elementor Free, then runs runtime smoke suites. It generates a real Elementor page from the responsive fixture and compares that page against the source at 1440, 1024, 768, and 390. The threshold is 0.95 similarity with no horizontal overflow.

### `wordpress-minimum`

Uses PHP 8.1 and WordPress 6.5.5 to validate the minimum supported WordPress environment.

### `elementor-pro-loop`

Runs only when `ELEMENTOR_PRO_ZIP_URL` is configured as a repository secret. Without the secret, the workflow reports that the licensed runtime gate is not configured; it must not be interpreted as Pro Loop evidence.

## Runtime evidence store

Evidence records contain:

- key and status;
- source and sanitized details;
- recorded timestamp and TTL;
- plugin version;
- WordPress, PHP, Elementor, Elementor Pro, and editor-mode versions.

Default TTL is 30 days. A version/environment mismatch makes an entry stale immediately.

Inspect evidence:

```bash
wp design-core evidence
```

Clear one entry:

```bash
wp design-core evidence --clear=responsive-visual-qa
```

Record a verified entry:

```bash
wp design-core record_evidence responsive-visual-qa \
  --status=pass \
  --source=manual \
  --details='{"viewports":"1440,1024,768,390","source":"generated-page-vs-reference"}'
```

## Required production evidence

Always required:

- `elementor-save-reload-render`
- `page-a-page-b-reuse`
- `global-design-system`
- `responsive-visual-qa`
- `security-ssrf-source`
- `registry-migration-rollback`
- `custom-widget-multi-instance`
- `native-widget-semantic-execution`

Conditionally required:

- `atomic-design-core-roundtrip` when V4/mixed mode is active;
- `elementor-pro-loop` when Elementor Pro and Loop capability are installed and claimed.

`atomic-public-api-capability` is informational and cannot replace the Atomic roundtrip.

## Readiness status

```bash
wp design-core readiness
```

- `not-ready`: at least one required capability/architecture check failed.
- `production-candidate`: required code/capabilities passed, but evidence is missing or stale.
- `production-ready`: every required and conditionally applicable evidence entry is a fresh pass.

## Release verification sequence

1. Run all standalone suites.
2. Run PHP lint under 8.1, 8.2, and 8.3.
3. Run `npm ci` and `npm run check`.
4. Run browser fixture checks.
5. Run WordPress/Elementor runtime suites.
6. Run generated-page visual QA.
7. Inspect `wp design-core readiness`.
8. Run `git diff --check`.
9. Obtain independent review with no open BLOCKER, CRITICAL, or HIGH findings.
10. Push and verify local `HEAD` equals fetched `origin/main`.
11. Verify GitHub Actions actually ran; a queued/skipped/account-blocked job is not a pass.
