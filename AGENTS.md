# AGENTS.md — Design Core developer-agent contract

## Mandatory validation rule

After modifying Design Core source code, the AI agent MUST run:

```
./scripts/ci-local.sh
```

The agent MUST NOT state that the task is complete until Local CI passes.
`--fast` is for iteration only; the final gate before commit/push is always
the FULL suite.

When Local CI fails:

1. read the failure,
2. identify the root cause,
3. modify the implementation,
4. rerun the relevant focused test,
5. rerun the complete `./scripts/ci-local.sh` suite,
6. repeat until PASS.

Never:

- ignore a failing test,
- remove a failing test merely to make CI green,
- weaken assertions without technical justification,
- hardcode PawCare-specific hacks into generic production conversion code,
- claim success without executing the tests.

## Pipeline truth

Optimize for the real pipeline, not green tests:

Figma / HTML / design intent → Design Core → accurate Design IR →
native editable Elementor → accurate browser render → measured verification.

Any correction required by PawCare must improve the generic Figma → Elementor
compiler architecture so future designs benefit from the same fix.

## Design Memory contract

Before repeating a known design/compiler pattern, agents SHOULD query Design Memory:

```
wp design-core-memory status
wp design-core-memory recommend --json='{"source_type":"figma","category":"asset-missing","asset_type":"svg"}'
```

Memory is evidence-driven, not an autonomous self-modifying code path:

- failed and partial corrections are retained only as incidents;
- a reusable lesson may be created only after a rendered correction roundtrip passes its target similarity and quality gate;
- verified lessons are advisory to the planner/compiler and never mutate Elementor by themselves;
- every learned lesson proposes a review-gated regression benchmark candidate;
- project/source-specific lessons must not be promoted to global scope without repeatable evidence;
- old lessons may be superseded by newer runtime evidence, but never silently rewritten by a model guess.

For Figma work, use the strict agent entrypoint instead of the legacy IR-only command whenever the goal is visual fidelity:

```
wp design-core-figma prepare '<figma-url>'
wp design-core-figma compile '<figma-url>'
wp design-core-figma build '<figma-url>' --page-id=<draft-id>
wp design-core-figma verify '<figma-url>' '<candidate-url>' --page-id=<draft-id>
```

The strict path always requests image fills, exact vector assets and a Figma-rendered reference. A Figma build is not complete until rendered verification passes.
