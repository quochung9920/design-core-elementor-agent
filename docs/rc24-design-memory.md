# RC24 — Verified Design Memory + strict Figma agent path

RC24 adds a persistent learning layer without allowing autonomous self-modifying compiler code.

## Goals

1. Remember recurring failure signatures and successful verified corrections.
2. Reuse only corrections that were proven by rendered verification.
3. Preserve failed attempts as incidents so agents can avoid repeating them.
4. Turn verified lessons into review-gated regression benchmark candidates.
5. Make strict Figma fidelity the preferred local-agent entrypoint.
6. Preserve small Figma vector/icon composites as exact SVG assets instead of generic Elementor containers.

## Memory lifecycle

```text
failure observed
    ↓
incident recorded
    ↓
correction applied
    ↓
render again
    ↓
visual + quality gate pass?
    ├─ no  → incident only
    └─ yes → verified lesson
                ↓
          benchmark candidate
                ↓
        available to future planner
```

A memory lesson contains a stable failure signature, conditions, correction evidence, scope, confidence and provenance. Lessons are bounded in WordPress options and never contain executable PHP/JS generated from source designs.

## Scopes

- `global`: generic compiler/runtime lessons proven broadly enough to reuse.
- `project`: project-specific patterns such as local components or brand conventions.
- `source`: one Figma/source-system mapping or source-specific convention.

The runtime never automatically promotes a project/source lesson to global scope.

## Built-in generic fidelity rules

RC24 seeds generic invariants for:

- vector/component asset preservation;
- small primitive size protection;
- mixed text composition;
- absolute anchor preservation;
- Figma FILL/flex semantics;
- image crop/focal position;
- font fidelity proof.

These rules are advisory. The live Elementor control schema and strict persistence validators remain authoritative.

## Figma asset preservation

`Design_Core_Elementor_Figma_Vector_Asset_Resolver` now identifies:

- leaf `VECTOR`, `BOOLEAN_OPERATION`, `LINE`, `STAR`, `POLYGON` nodes;
- small `ELLIPSE`/`RECTANGLE` primitives;
- small vector-only `INSTANCE`, `COMPONENT`, `GROUP`, and `FRAME` composites.

When Figma successfully exports a composite as SVG, the transport collapses that source composite to one exact vector media leaf before Design IR conversion. This prevents internal decorative rectangles/frames from becoming stretchable Elementor containers.

The rule is geometry/type driven and contains no PawCare node IDs, names or design-specific hacks.

## Strict Figma workflow for local agents

Use:

```bash
wp design-core-figma prepare '<figma-url>'
wp design-core-figma compile '<figma-url>'
wp design-core-figma build '<figma-url>' --page-id=<draft-id>
wp design-core-figma verify '<figma-url>' '<candidate-url>' --page-id=<draft-id>
```

This path always enables:

- image fill resolution;
- vector asset resolution;
- Figma reference export;
- strict Figma fidelity service;
- Design Memory preflight recommendations;
- rendered verification before completion.

The legacy `wp design-core figma_url` command remains useful for inspection but must not be treated as proof of visual fidelity.

## Memory CLI

```bash
wp design-core-memory status
wp design-core-memory lessons
wp design-core-memory incidents
wp design-core-memory recommend --json='{"source_type":"figma","category":"asset-missing","asset_type":"svg"}'
```

A manual `learn` command exists for agents that have independent verified evidence:

```bash
wp design-core-memory learn --json='<verified-run-json>'
```

The learning engine rejects runs below the target visual score, non-verified final status, non-publishable quality gates, and unresolved high-severity structural issues.

## Automatic learning

`Design_Core_Elementor_Visual_Correction_Service` v3 records failed correction rounds as incidents. When a multi-iteration correction roundtrip reaches a final visual PASS, each actually applied correction directive is handed to the verified learning engine with the before/after similarity evidence.

A final PASS is required; merely applying a control change never creates a lesson.

## Regression safety

`tests/design-memory/run.php` verifies that:

- vector asset loss gets a stable generic failure signature;
- a stretched 9px primitive is recognized as a flex-stretch failure;
- failed corrections never become lessons;
- verified corrections do become lessons;
- learned lessons are retrievable for future matching contexts;
- benchmark candidates are proposed only from verified lessons;
- small vector-only Figma composites are exported as a single SVG asset;
- large layout/media frames are not incorrectly collapsed into icon assets.

The test is part of `scripts/ci-local.sh`.

## Non-goals

RC24 does not let a model rewrite production compiler code from memory. It does not bypass Elementor runtime control discovery, strict structure validation, approval gates or rendered verification. Persistent memory is a verified evidence layer for future planning and debugging, not an authority above runtime truth.
