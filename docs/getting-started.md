# Getting Started

This guide covers installation, activation, first-run checks, browser tooling, and the supported ways to interact with Design Core Elementor.

## What the plugin does

Design Core Elementor converts HTML and CSS into editable Elementor content through a governed pipeline:

```text
Source HTML/CSS
→ bounded analysis
→ canonical Design IR v4
→ normalization and reuse lookup
→ BuildPlan v1
→ strategy execution
→ Elementor V3 or public V4 adapter
→ save, reload, render, QA, registry synchronization
```

It is not a generic HTML paste widget. Its primary goal is to preserve semantics, responsive intent, reusable design-system decisions, and Elementor editability.

## Requirements

Required:

- WordPress 6.5 or newer.
- PHP 8.1, 8.2, or 8.3.
- Elementor installed and activated.
- An administrator account for the admin UI and REST diagnostics.

Optional:

- WP-CLI for diagnostics, conversion, evidence, registry import/export, and browser analysis commands.
- Node.js 20 or newer and npm for Playwright-based browser analysis and image comparison.
- Chromium installed through Playwright when browser analysis is enabled.
- Elementor Pro only when a site integration claims concrete Pro functionality such as Loop Builder. The plugin does not infer or write private Elementor Pro storage.

## Installation

### Install from a working tree

Copy or link the repository to:

```text
wp-content/plugins/design-core-elementor
```

Then activate it:

```bash
wp plugin activate design-core-elementor
```

### Install browser tooling

From the plugin directory:

```bash
npm ci
npx playwright install chromium
npm run check
```

Normal frontend requests do not require Playwright. It is used only when browser analysis, screenshots, or visual comparison are requested.

## Activation behavior

On activation, the plugin runs the versioned registry migrator. During normal bootstrap it:

1. loads domain contracts, analysis services, registries, executors, Elementor adapters, QA services, REST routes, and WP-CLI commands;
2. initializes component, widget, and token registry defaults;
3. waits for Elementor before registering the plugin category and runtime widgets;
4. displays an administrator warning instead of booting Elementor-dependent features if Elementor is missing.

The plugin version is declared in `design-core-elementor.php` as `1.0.0-rc12`.

## First-run verification

Run:

```bash
wp plugin status design-core-elementor
wp design-core compatibility
wp design-core capabilities
wp design-core breakpoints
wp design-core readiness
```

Expected interpretation:

- `compatibility` reports the live WordPress, PHP, Elementor, and editor environment.
- `capabilities` reports detected runtime abilities. Detection is not proof that a complete conversion works.
- `breakpoints` reports the active Elementor breakpoint labels and widths.
- `readiness` returns `not-ready`, `production-candidate`, or `production-ready`.

A fresh installation is normally `production-candidate` until all required runtime evidence has been recorded in the current environment.

## Admin interface

The plugin adds a top-level **Design Core** menu with these screens:

- **Design Core** — current version, editor mode, browser-analysis availability, and registry counts.
- **HTML Import** — administrator-only HTML/CSS conversion interface.
- **Registry** — component masters and usage counts.
- **Settings** — design tokens, conversion rules, and QA thresholds.
- **Production Readiness** — capability checks and runtime-evidence status.

All screens require `manage_options`.

## First conversion with WP-CLI

For a small source fixture:

```bash
wp design-core convert_html \
  --title='Imported landing page' \
  --html='<section class="hero"><h1>Hello</h1><p>Editable content.</p></section>' \
  --css='.hero{display:flex;flex-direction:column;gap:24px;padding:64px;background:#f5f5f5}'
```

The conversion command prints the application-service result and exits with an error when no page was created.

Conversion defaults come from the Settings screen:

- pages are created as drafts;
- remote assets are imported into the Media Library;
- registry reuse threshold is `0.75`;
- browser analysis is disabled until explicitly enabled.

## Analyze before converting

To inspect a local HTML file with the browser analysis service:

```bash
wp design-core browser_analyze /absolute/path/to/source.html
```

Or call the Node analyzer directly:

```bash
node scripts/browser-analyze.mjs /absolute/path/to/source.html 1440,1366,1024,767,390
```

The browser analyzer produces viewport-keyed JSON with stable DOM paths, computed geometry, overflow signals, and intrinsic image evidence. It enforces bounded input, DOM size, viewport count, process time, and output size.

## Screenshots and visual comparison

```bash
node scripts/capture-page.mjs <url-or-file> 1440 /tmp/reference.png
node scripts/capture-page.mjs <url-or-file> 1440 /tmp/candidate.png
node scripts/visual-compare.mjs /tmp/reference.png /tmp/candidate.png
```

A fixture compared with itself validates only the tooling. Production visual evidence must compare a generated Elementor page with the real source/reference at the governed responsive viewports.

## Next reading

- [Architecture](architecture.md)
- [Conversion pipeline](conversion-pipeline.md)
- [Contracts](contracts.md)
- [Elementor integration](elementor-integration.md)
- [Configuration and interfaces](configuration-and-interfaces.md)
- [Testing and runtime evidence](testing-and-runtime-evidence.md)
- [Security and recovery](security-and-recovery.md)
- [Development guide](development-guide.md)
- [Troubleshooting](troubleshooting.md)
