# Security and Recovery

Design Core accepts complex markup, CSS, remote asset URLs, registry payloads, and platform data. The system therefore treats security, validation, and rollback as pipeline requirements rather than optional QA.

## Trust boundaries

Untrusted or semi-trusted inputs include:

- imported HTML and CSS;
- remote image URLs and response metadata;
- registry import JSON;
- REST request parameters;
- Elementor runtime schemas and capability availability;
- browser-analysis process output;
- generated Elementor payloads before read-back validation.

No downstream success claim should rely on an input merely being parseable.

## Source limits

The conversion application service rejects HTML or CSS larger than 1 MiB. The security policy separately exposes filterable defaults:

- HTML: 2 MiB;
- CSS: 1 MiB.

The analysis engine also bounds:

- 2,500 HTML elements;
- nesting depth 128;
- stack-aware closing tags.

Browser analysis repeats source preflight before launching Chromium and adds bounded viewport count, DOM materialization, process timeout, and output-size controls.

## Remote asset and SSRF protection

Only HTTP and HTTPS URLs are accepted. Validation rejects:

- malformed URLs;
- localhost and `.local` hosts;
- private/reserved IPv4 and IPv6 addresses;
- unresolved hosts;
- unsafe schemes;
- redirects/targets rejected by WordPress safe HTTP handling.

Every resolved address must be public. The preflight request uses `wp_safe_remote_head()` with unsafe URL rejection, bounded timeout, and bounded redirects.

Default remote asset policy:

- maximum declared size: 20 MiB;
- MIME types: JPEG, PNG, GIF, WebP, and AVIF;
- successful HTTP response required.

The asset importer still validates the downloaded asset and tracks newly created attachments for rollback.

## Scoped CSS safety

Custom CSS is not a generic escape hatch. The fallback service requires owner scoping and rejects resource-loading or parsing tricks including:

- external/data URLs;
- `@import`;
- JavaScript schemes;
- CSS escapes and control characters;
- `expression()`;
- malformed declarations;
- unowned selectors;
- unsafe resource functions.

A page stylesheet inside an HTML widget is an architecture failure.

## Admin and REST authorization

Admin screens and REST diagnostics require `manage_options`.

Settings writes require a WordPress nonce. REST routes use a permission callback for every registered endpoint. Read-only diagnostic routes may expose registry/design-system structure and therefore are not public.

## Contract validation

The following boundaries validate before persistence or execution:

- Design IR v4;
- BuildPlan v1;
- ExecutorResult;
- RegistryItem v2;
- Elementor element trees;
- runtime evidence records.

Malformed nested registry values are rejected before sanitization so sanitization cannot transform an invalid shape into apparently valid data.

## Fail-closed execution

Fail-closed means:

- unknown Decision Engine strategy throws;
- missing runtime control schema does not produce guessed settings;
- adapter-target mismatch is unsupported;
- missing executor is unsupported;
- invalid executor result fails;
- fallback requires explicit authorization and diagnostics;
- empty Elementor reload/render fails;
- direct `_elementor_data` metadata writes are not a lifecycle fallback;
- missing browser evidence is reported as unavailable, not inferred as pass.

## Conversion transaction

A conversion transaction begins before analysis and tracks mutable artifacts:

- created WordPress posts;
- created Media Library attachments;
- registry snapshots;
- Elementor Kit/global-style mutation state.

Any failed stage calls rollback. A successful conversion commits only after save, reload, render, structural QA, and registry synchronization.

Rollback results are included in failure output and observability records. A rollback error must not be hidden behind the original stage failure.

## Registry recovery

Versioned registries provide:

- schema validation;
- migration;
- backup;
- verified persistence;
- import locking;
- complete-payload validation;
- snapshot restoration on failed import.

Use service methods or:

```bash
wp design-core export
wp design-core import --json='...'
```

Do not write registry options directly.

## Runtime evidence integrity

Evidence is version- and environment-scoped. A `pass` record becomes stale when relevant runtime versions change or TTL expires.

Capability detection and code existence are not equivalent to runtime evidence. Examples:

- public Atomic ability exists ≠ Design Core Atomic round trip passed;
- Elementor widget slug registered ≠ widget has usable content controls;
- screenshot tool runs ≠ generated Elementor page matches the source;
- saved `__globals__` ID exists ≠ generated CSS uses a live Kit preset.

## Security verification checklist

Before release:

- run SSRF/private-address tests;
- run source-size and HTML-complexity tests;
- validate asset MIME/size behavior;
- run scoped-CSS adversarial tests;
- validate malformed registry payloads;
- verify REST/admin capability and nonce behavior;
- search for runtime PHP generation, `eval`, unsafe unserialize, and path traversal;
- test conversion rollback after created post, attachment, registry, and Kit mutations;
- ensure logs and documentation contain no secrets.

## Incident response

If a conversion fails:

1. capture the returned `conversion_id`;
2. inspect `wp design-core conversions`;
3. identify the first failed stage;
4. inspect rollback details;
5. verify no draft page or attachment remained unexpectedly;
6. verify registry and Kit state against a pre-operation snapshot;
7. reproduce with the smallest source fixture;
8. add a failing regression test before changing production code.

If registry state is suspect, export it before any repair. Never erase options as the first troubleshooting step.
