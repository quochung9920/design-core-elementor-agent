# Production Checklist

## Architecture

- [ ] Design IR v4 is canonical after analysis; no execution-time HTML/CSS reparsing.
- [ ] IR and BuildPlan validate fail-closed.
- [ ] Every strategy uses the common executor and result contracts.
- [ ] Elementor details remain in platform adapters.
- [ ] Responsive inheritance removes redundant overrides.
- [ ] Global adapters use only public platform APIs.

## Registry and recovery

- [ ] Component and widget items validate against RegistryItem v2.
- [ ] Fingerprints are v2.
- [ ] Page A and Page B share a master while preserving instance content.
- [ ] Import validates all sections before writes and rolls back all sections on failure.
- [ ] Migration backup/validation/rollback passes in WordPress.

## Security

- [ ] REST/admin capability and nonce tests pass.
- [ ] SSRF localhost/private/metadata cases are blocked.
- [ ] MIME and size policies pass.
- [ ] Malformed registry nested fields return `invalid-payload` without fatal errors.
- [ ] No runtime PHP generation, path traversal, `eval`, or unsafe unserialize.

## Runtime evidence

Required fresh PASS:

- [ ] `elementor-save-reload-render`
- [ ] `page-a-page-b-reuse`
- [ ] `responsive-visual-qa` at 1440, 1024, 768, and 390 or active breakpoint matrix
- [ ] `global-design-system`
- [ ] `security-ssrf-source`
- [ ] `registry-migration-rollback`
- [ ] `custom-widget-multi-instance`
- [ ] `native-widget-semantic-execution`

Conditional:

- [ ] `atomic-design-core-roundtrip` only if V4/Atomic is active
- [ ] `elementor-pro-loop` only if Elementor Pro Loop is installed and claimed

## Compatibility and release

- [ ] PHP 8.1, 8.2, and 8.3 suites pass.
- [ ] WordPress minimum/latest and Elementor stable/previous stable are tested.
- [ ] PHP syntax and `git diff --check` pass.
- [ ] GitHub Actions jobs actually execute; queued jobs are not reported as PASS.
- [ ] Local commit SHA equals `origin/main` after push.
- [ ] Independent review has no open BLOCKER, CRITICAL, or HIGH findings.

`PRODUCTION READY` is forbidden until all applicable runtime evidence is fresh PASS. Passing architecture tests alone permits at most `PRODUCTION CANDIDATE`.
