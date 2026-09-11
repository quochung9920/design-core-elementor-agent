# Local CI

Single authoritative validation command (run from anywhere inside the repo):

```
./scripts/ci-local.sh
```

Fast feedback during development (syntax + contracts + node checks):

```
./scripts/ci-local.sh --fast
```

Before every commit/push the AI agent MUST run the FULL suite, not just `--fast`.

## Prerequisites

- WSL2 (Ubuntu) — also runs on plain Linux/macOS, Docker check adapts.
- Node 20+ and npm (`node --version`, `npm --version`).
- PHP: any `php` in PATH, or the wordpress-dev runtime (`/home/dev/wordpress-dev/runtime/usr/bin/php8.5`).
- Docker — required for the PHP 8.1/8.2/8.3 matrix. Without Docker the matrix
  reports BLOCKED and full CI exits non-zero (a missing matrix is never PASS).
- Playwright Chromium: `npx playwright install chromium` (once; verified by
  `node scripts/browser-probe.mjs`). First-time helper: `./scripts/setup-local-ci.sh`.

## What full CI runs

1. Environment (WSL, PHP, Node, npm, Docker, Playwright/Chromium).
2. PHP matrix 8.1/8.2/8.3 via `docker/ci/php/Dockerfile` (Composer inside).
3. `php -l` over all project PHP files (excl. `vendor/`, `node_modules/`).
4. Core contracts: architecture, registry, native-fidelity, strict-native,
   responsive-compiler, design-intelligence, design-brain, rc19, rc20,
   reference-integration, fixed-width-governance, figma-fidelity (run +
   adapter), pawcare-benchmark.
5. `npm ci --ignore-scripts --no-audit --no-fund` then `npm run check`.
6. Multi-viewport browser evidence 1440/1024/768/390 via
   `scripts/browser-analyze-target.mjs` (fails on missing viewport, crash,
   or invalid JSON).
7. Responsive gate: no horizontal overflow at 390/768.
8. Screenshot engine determinism: identical ref/candidate similarity >= 0.999.
9. PawCare golden benchmark `tests/pawcare-benchmark/run.php` (structure,
   geometry, typography, content, Elementor-native — never pixel-only).

## Artifacts

Temporary output goes to `.artifacts/local-ci/` (gitignored, never committed).

## PawCare benchmark

Real-world regression target: PawCare Figma node `4:1049`
(`tests/fixtures/figma/pawcare-4-1049/`). Normal CI runs fully offline from
stored fixtures. To refresh from live Figma (needs token, never committed):

```
FIGMA_TOKEN=... ./scripts/update-figma-fixture.sh pawcare
```

## Troubleshooting

- `php: command not found` — `source /home/dev/wordpress-dev/env.sh` or add
  the runtime to PATH; ci-local.sh auto-detects both.
- Playwright BLOCKED — `npx playwright install chromium`, then
  `node scripts/browser-probe.mjs`.
- Docker missing — install Docker; until then full CI is BLOCKED by design.
- Visual similarity < 0.999 on identical shots — screenshot engine is broken,
  fix `scripts/capture-page.mjs` first, never lower the threshold.
