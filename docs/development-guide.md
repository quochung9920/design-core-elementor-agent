# Development Guide

## Repository map

```text
assets/                 Admin CSS and governed local import fixtures
core/                   Domain, application, execution, adapters, QA, security
includes/               Bootstrap, settings, admin UI, plugin integration
references/             Design and conversion policy references
scripts/                Playwright analysis, capture, visual comparison, runtime fixtures
src/                    Supporting source modules retained by the project
strategies/             Strategy-specific implementation modules
widgets/                Base and generated/runtime Elementor widget classes
tests/                  Standalone contract suites, WordPress runtime smokes, fixtures
.github/workflows/       Production CI
```

The bootstrap load order is explicit in `design-core-elementor.php`. Add a new core class to that list only when it cannot be autoloaded by the existing loader conventions.

## Dependency direction

```text
Domain contracts
↑ Application planning and normalization
↑ Strategy execution
↑ Elementor/platform adapters
↑ WordPress, browser, media infrastructure
```

Domain data must not depend on Elementor storage. The conversion service coordinates services; it must not absorb adapter-specific control mapping.

## Coding conventions

- PHP 8.1 is the minimum language/runtime target.
- Follow WordPress escaping, sanitization, capability, nonce, HTTP, and option APIs.
- Keep class names under the `Design_Core_Elementor_` prefix.
- Keep comments and documentation in English.
- Prefer small services with explicit ownership over shared utility buckets.
- Do not hide exceptions at contract, persistence, or verification boundaries.
- Do not add direct `_elementor_data` writes as a fallback.
- Do not write generated PHP into the plugin at runtime.

## Test-driven workflow

For every behavior change:

1. add a focused test that describes one missing behavior;
2. run it and confirm it fails for the intended reason;
3. implement the smallest compatible change;
4. run the focused suite;
5. run all standalone suites;
6. run runtime smoke tests when the behavior crosses WordPress/Elementor boundaries;
7. run JS syntax/browser checks when scripts change;
8. run PHP lint and `git diff --check`;
9. inspect the final diff and obtain independent review for production-impacting changes.

Do not accept a newly written test that passes before implementation unless it is explicitly a characterization test.

## Adding a canonical IR field

1. Define the semantic meaning without Elementor terminology.
2. Add validator coverage first, including invalid type/value/hierarchy cases.
3. Populate it only in the analysis layer or a canonical enrichment service.
4. Preserve it through normalization.
5. Add BuildPlan binding only if execution needs it.
6. Map it in the appropriate platform adapter.
7. Add persisted/generated output evidence.
8. Document the field in `docs/contracts.md`.

If the source value cannot be represented safely, retain an explicit canonical fallback declaration or unsupported diagnostic; do not coerce it into an unrelated field.

## Adding a widget control adapter

1. Add a failing focused adapter test.
2. Identify the canonical widget/element semantic type.
3. Query the active runtime through `Control_Schema_Registry`.
4. Implement `Widget_Control_Adapter_Interface`.
5. Map only compatible control types.
6. Handle responsive suffixes through the active breakpoint registry.
7. Register the adapter in `Widget_Control_Mapper`.
8. Verify saved JSON and generated CSS in a real Elementor runtime.
9. Confirm architecture audit has no unexplained Custom CSS or HTML stylesheet.

Never copy control IDs from a different Elementor version without runtime validation.

## Adding a strategy

1. Add the strategy to `Build_Plan_Validator::STRATEGIES` only after defining its contract.
2. Extend Decision Engine output deliberately.
3. Add an explicit Decision → BuildPlan mapping.
4. Implement `Strategy_Executor_Interface`.
5. Return a valid `ExecutorResult` for success and all failures.
6. Define rollback for created artifacts.
7. Define whether fallback is allowed and to which strategy.
8. Add planner, executor, fallback, and multi-root regression tests.
9. Add runtime evidence if the strategy supports a new production claim.

An unsupported strategy must never report success with empty elements.

## Adding Elementor V4 behavior

- Use public abilities only.
- Check ability availability and operation support separately.
- Use `dry_run=false` for real runtime evidence.
- Persist IDs returned by the public API.
- Record downgrade reason when V3 handles an unsupported structure.
- Reload/render with the actual persistence mode.
- Do not infer private Atomic node or variable storage.

## Adding Pro integration

Keep proprietary/private integration behind a dedicated adapter. For Loop Builder, implement the stages exposed by `Pro_Loop_Adapter` rather than inserting guessed Elementor Pro metadata into the generic executor.

Licensed runtime behavior requires licensed runtime evidence before it can gate a release claim.

## Building an editable section from static authority

Use this sequence:

1. Identify the exact source section and capture it at 1440, 1366, 1024, 767, and 390.
2. Record section, constrained container, heading, lead, repeated items, and media geometry.
3. Classify section layout ownership and media height policy.
4. Snapshot current Elementor roots before writing.
5. Create a platform-neutral blueprint/canonical IR fixture.
6. Write and run a failing blueprint test.
7. Discover runtime control schemas for every intended widget.
8. Import local PNG/WebP assets into Media Library and retain attachment IDs.
9. Map native controls first; use owner-scoped CSS only for unsupported declarations.
10. Save through Elementor Document API.
11. Read back `_elementor_data`, reload, render, and regenerate CSS.
12. Compare untouched root hashes.
13. Verify generated responsive and hover CSS.
14. Run architecture audit and browser geometry/pixel QA.

### Layout standards

- Parent Container Gap owns sibling spacing.
- Use wrapper Containers when sibling interval groups differ.
- Avoid routine child margins.
- Separate full-bleed surface ownership from constrained content ownership.
- Prefer `width:100%`, global max width, and fluid gutters.
- Prefer auto/intrinsic/aspect-ratio/min-height media behavior.
- Treat fixed width, fixed section height, `100vh`, and unstable `100dvh` as governed exceptions.

### Project-specific provisioning fixtures

The repository currently includes:

- `scripts/provision-air-why.php`
- `scripts/provision-air-compare.php`
- `scripts/smoke-native-control-mapping.php`

The first two are page-specific fixtures for the Air Consolidation design and are not generic installers. Review their target page ID, expected existing roots, assets, and assertions before running them. They intentionally fail closed if prerequisites or root preservation checks do not match.

## Documentation updates

When changing behavior, update all relevant surfaces:

- README feature/status summary;
- `docs/architecture.md` for module/data-flow changes;
- `docs/contracts.md` for schema changes;
- `docs/fallback-policy.md` for strategy/fallback changes;
- `docs/configuration-and-interfaces.md` for UI, CLI, REST, option, or filter changes;
- `docs/testing-and-runtime-evidence.md` for gates/evidence changes;
- `docs/security-and-recovery.md` for trust boundaries or rollback behavior.

Documentation must describe implemented behavior and clearly label optional, conditional, or unavailable runtime features.

## Pre-commit checklist

```bash
npm ci
npm run check

# Run every standalone suite.
for suite in tests/*/run.php; do php "$suite"; done

# Runtime suites require a WordPress + Elementor environment.
wp eval-file tests/production-smoke.php
wp eval-file tests/integration/page-reuse-smoke.php

git diff --check
```

Also lint every PHP file under PHP 8.1, 8.2, and 8.3 through CI or equivalent containers.
