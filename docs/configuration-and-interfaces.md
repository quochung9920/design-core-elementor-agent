# Configuration and Interfaces

## Administrator settings

The **Design Core → Settings** screen stores three groups.

### Design tokens

Supported groups:

- `colors`
- `typography`
- `spacing`
- `radius`

Settings input is sanitized before the token service persists it. Colors and font names are stored as text values; spacing and radius values are numeric.

### Conversion rules

| Setting | Default | Meaning |
|---|---:|---|
| `auto_match_threshold` | `0.75` | Similarity threshold used by reuse decisions |
| `create_as_draft` | `true` | Create converted pages as drafts instead of publishing |
| `import_assets` | `true` | Import governed remote images into Media Library |
| `browser_analysis` | `false` | Run Playwright computed-style analysis when available |

The rules are stored in the `design_core_conversion_rules` option.

### QA thresholds

| Setting | Default | Meaning |
|---|---:|---|
| `color_limit` | `8` | Maximum tolerated color diversity for drift checks |
| `font_limit` | `3` | Maximum tolerated font-family diversity |
| `min_score` | `80` | Minimum structural/visual QA score |

The thresholds are stored in the `design_core_qa_thresholds` option.

## WP-CLI reference

All commands are registered under `wp design-core`.

| Command | Purpose | Example |
|---|---|---|
| `capabilities` | Inspect live Elementor/browser/public-API capabilities | `wp design-core capabilities` |
| `readiness` | Run capability and evidence readiness audit | `wp design-core readiness` |
| `compatibility` | Validate WordPress/PHP/Elementor environment | `wp design-core compatibility` |
| `registry` | Print component registry | `wp design-core registry` |
| `export` | Export component/widget/token registries | `wp design-core export` |
| `import` | Transactionally import registry JSON | `wp design-core import --json='{"components":[]}'` |
| `generate_widget` | Create a persistent runtime-widget definition | `wp design-core generate_widget notice --title='Notice'` |
| `convert_html` | Convert HTML/CSS into a new Elementor page | `wp design-core convert_html --title='Page' --html='<section>...</section>' --css='...'` |
| `qa_audit` | Audit an existing Elementor page | `wp design-core qa_audit 42` |
| `match_component` | Compare semantic similarity of two fragments | `wp design-core match_component --html1='...' --html2='...'` |
| `advanced_analyze` | Print legacy/deep semantic analysis | `wp design-core advanced_analyze --html='...' --css='...'` |
| `tokens` | Print active Design Core tokens | `wp design-core tokens` |
| `sync_globals` | Synchronize tokens with active Elementor globals/classes | `wp design-core sync_globals` |
| `breakpoints` | Print discovered Elementor breakpoints | `wp design-core breakpoints` |
| `conversions` | Print recent observability records | `wp design-core conversions` |
| `evidence` | Print or clear runtime evidence | `wp design-core evidence --clear=responsive-visual-qa` |
| `record_evidence` | Record a governed manual/runtime result | `wp design-core record_evidence key --status=pass --source=manual --details='{"note":"verified"}'` |
| `browser_analyze` | Analyze a local HTML file through Playwright | `wp design-core browser_analyze /tmp/source.html` |
| `visual_compare` | Compare two PNG screenshots | `wp design-core visual_compare ref.png candidate.png` |

Treat `record_evidence` as an assertion. Record `pass` only when the named runtime behavior was actually exercised in the current version and environment.

## REST API

Namespace:

```text
/wp-json/design-core-elementor/v1
```

All routes require an authenticated user with `manage_options`.

| Route | Method | Result |
|---|---|---|
| `/capabilities` | GET | Live capability scan |
| `/components` | GET | Component registry |
| `/widgets` | GET | Widget registry |
| `/tokens` | GET | Design tokens |
| `/search?q=...` | GET | Component and widget registry search |
| `/diagnostics` | GET | Versions, readiness, and evidence |
| `/readiness` | GET | Production-readiness audit |
| `/conversions` | GET | Recent conversion observability records |
| `/evidence` | GET | Runtime evidence entries with freshness |

Example with WordPress application-password authentication:

```bash
curl --user 'admin:application-password' \
  https://example.test/wp-json/design-core-elementor/v1/readiness
```

Do not embed credentials in source files or shell history. Use an appropriate secret mechanism for real environments.

## Public filters

The current code exposes these filters:

| Filter | Default | Purpose |
|---|---:|---|
| `design_core_elementor_run_browser_analysis` | conversion setting | Enable/disable browser analysis |
| `design_core_elementor_max_html_bytes` | 2 MiB | Security-policy HTML limit |
| `design_core_elementor_max_css_bytes` | 1 MiB | Security-policy CSS limit |
| `design_core_elementor_max_asset_bytes` | 20 MiB | Remote asset content-length limit |
| `design_core_elementor_allowed_remote_image_mimes` | JPEG, PNG, GIF, WebP, AVIF | Allowed remote image MIME types |
| `design_core_elementor_runtime_evidence_ttl` | 30 days | Evidence freshness window |

Example:

```php
add_filter(
    'design_core_elementor_max_asset_bytes',
    static function () {
        return 10 * 1024 * 1024;
    }
);
```

Filters change policy; they do not bypass URL validation, private-network blocking, contract validation, or Elementor lifecycle requirements.

## Persistent options

Important option keys include:

| Option | Owner |
|---|---|
| `design_core_conversion_rules` | conversion settings |
| `design_core_qa_thresholds` | QA settings |
| `design_core_elementor_tokens_v2` | Design Token Service |
| `design_core_elementor_components` | Component Registry |
| `design_core_elementor_widgets` | Widget Registry |
| `design_core_elementor_runtime_evidence` | Runtime Evidence Store |
| `design_core_elementor_conversion_logs` | Observability |

Registry and token payloads are versioned contracts. Do not update these options directly; use their services or registry import/export.

## Runtime evidence statuses

Allowed statuses:

- `pass`
- `fail`
- `unavailable`
- `partial`

`skip` input is normalized to `unavailable`.

Evidence becomes stale when:

- its TTL expires;
- plugin version changes;
- WordPress, PHP, Elementor, Elementor Pro, or editor mode differs from the recorded environment.

## Browser tool commands

```bash
# Syntax checks
npm run check

# Computed-style and geometry analysis
node scripts/browser-analyze.mjs source.html 1440,1366,1024,767,390

# Capture
node scripts/capture-page.mjs source.html 390 /tmp/source-390.png
node scripts/capture-page.mjs 'https://example.test/?page_id=42' 390 /tmp/page-390.png

# Pixel comparison
node scripts/visual-compare.mjs /tmp/source-390.png /tmp/page-390.png
```

The Node tools print JSON to stdout so they can be consumed by CI or stored as evidence artifacts.
