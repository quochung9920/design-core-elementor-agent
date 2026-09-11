# Troubleshooting

## Elementor is missing

Symptom:

```text
Design Core Elementor requires Elementor to be installed and activated.
```

Fix:

```bash
wp plugin install elementor --activate
wp plugin activate design-core-elementor
```

Then run `wp design-core compatibility`.

## Readiness is `production-candidate`

This usually means required capabilities passed but runtime evidence is missing or stale.

```bash
wp design-core readiness
wp design-core evidence
```

Check each failed evidence entry. Do not record a manual pass unless the named behavior has actually run in the current environment and plugin version.

## Evidence became stale after an update

This is expected. Evidence is invalidated by plugin, WordPress, PHP, Elementor, Elementor Pro, editor-mode changes, or TTL expiry. Re-run the corresponding runtime gates.

## Browser analysis is unavailable

Check:

```bash
node --version
npm ci
npx playwright install chromium
npm run check
```

Then verify the PHP runtime permits process execution. Browser analysis is optional for normal frontend requests; when unavailable it should report `unavailable`, not fail ordinary page rendering.

## Browser analysis times out or returns oversized output

Reduce source complexity and reproduce with a smaller fixture. The browser service intentionally bounds source size, DOM materialization, viewport count, runtime, and output bytes. Do not raise limits before identifying whether the source contains accidental duplication or malformed nesting.

## Source complexity errors

Common codes:

- `design_core_html_element_limit`
- `design_core_html_depth_limit`
- `design_core_html_too_large`
- `design_core_css_too_large`

Validate source markup, remove duplicated nodes, split oversized imports into semantic roots, and keep CSS scoped to the imported design.

## Remote image import fails

Possible causes:

- private/local URL;
- DNS resolution failure;
- unsafe redirect;
- MIME not in the allowlist;
- declared or downloaded size exceeds policy;
- Media Library filesystem permissions;
- target host blocks HEAD/GET requests.

Do not disable SSRF protection to import an internal URL. Download the approved image through a trusted workflow, validate it, then import it as a local Media Library asset.

## Native control mapping reports unsupported

The active Elementor version may expose a different control stack or type. Inspect the runtime schema instead of guessing a control ID. Confirm:

- Elementor has completed plugin bootstrap;
- the intended widget is registered;
- optimized `style_controls` and common controls are present;
- canonical value type matches control type;
- a widget is not a Pro upsell placeholder with no functional controls.

If no compatible control exists, use a governed owner-scoped CSS fallback only for a declaration allowed by policy.

## Saved data exists but generated CSS is missing

Stored Elementor JSON is not enough. Check:

1. Elementor Document API save returned success;
2. stored `_elementor_data` decodes and reloads;
3. page and active Kit CSS regeneration can write to uploads;
4. expected responsive/global selectors appear in generated CSS;
5. filesystem ownership allows the web user to write Elementor CSS files.

Do not patch `_elementor_data` directly as a workaround.

## Global reference exists but styling is wrong

Verify the referenced Kit ID exists and the generated Kit/page CSS contains the effective rule. Typography is bound globally only when all declared desktop and responsive values match. A local responsive difference should remain local rather than be discarded.

Run:

```bash
wp design-core sync_globals
wp design-core tokens
wp design-core qa_audit <page-id>
```

## User Kit Custom CSS was overwritten

The global layout service must modify only content between:

```text
/* DESIGN CORE GLOBAL LAYOUT START */
/* DESIGN CORE GLOBAL LAYOUT END */
```

Content outside the markers must be preserved. If it was not, stop synchronization, restore the Kit snapshot, and add a regression test for managed-block merging before changing code.

## Page lost sections after a fallback

Each BuildPlan item represents one root. A recoverable failure should fall back for that item and continue. Run the multi-section fallback smoke and inspect execution diagnostics:

```bash
wp eval-file wp-content/plugins/design-core-elementor/tests/multi-section-fallback-smoke.php
```

A failure that discards unrelated roots is a release blocker.

## V4 save downgraded to V3

Inspect the `_design_core_elementor_atomic_fallback` post meta and conversion diagnostics. A downgrade is expected when public Atomic APIs cannot represent the structure. It is safer than writing undocumented Atomic data.

After downgrade, reload and render must use the V3 adapter.

## Pro Loop falls back to native composition

A detected Elementor Pro class is not sufficient. The dedicated adapter needs a concrete, validated template/query/grid integration. Without it, Design Core intentionally uses `native-compose` and records diagnostics.

Configure the licensed runtime integration and run the Pro Loop gate before claiming native Loop support.

## Registry import fails

Registry import validates the complete payload and uses locking/snapshots. Steps:

1. export current registry;
2. validate top-level sections and RegistryItem schema versions;
3. check nested fingerprint/master/instance types;
4. ensure no concurrent import lock is active;
5. retry only after the payload is corrected;
6. verify snapshots were restored after failure.

Do not edit the registry options manually.

## Conversion created unexpected artifacts after failure

Use the conversion ID:

```bash
wp design-core conversions
```

Inspect rollback details and verify created posts, attachments, Kit changes, and registry changes. Preserve evidence before manual cleanup.

## WP-CLI `eval-file` fails at `declare(strict_types=1)`

`wp eval-file` evaluates source inside a wrapper. A top-level strict-types declaration is no longer the first statement and causes a fatal error. Remove it from provisioning/runtime smoke files intended for `eval-file`.

## Docker is unavailable inside WSL

If the Linux `docker` command is absent but Docker Desktop is installed, enable WSL integration or use the Windows client from WSL:

```bash
docker.exe version
docker.exe compose ps
```

Do not report runtime tests as passed until the container command and test output are actually available.

## GitHub Actions did not run

Check the workflow run and job annotations:

```bash
gh run list --branch main --limit 5
gh run view <run-id>
```

A workflow blocked by billing, account lock, missing runner, or configuration is not a code-test failure, but it is also not a pass. Report the exact external blocker.

## Reporting a bug

Include:

- plugin version and commit SHA;
- WordPress/PHP/Elementor/Elementor Pro versions;
- editor mode;
- minimal HTML/CSS fixture;
- conversion ID and first failed stage;
- BuildPlan strategy/fallback diagnostics;
- relevant readiness/evidence entries;
- exact focused and runtime test output;
- screenshots/geometry at affected viewports when visual.
