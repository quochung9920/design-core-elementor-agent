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

## Strict Figma workflow

A Figma URL is an authoritative design source, not an open-ended design prompt.
Agents MUST use the strict Figma fidelity path instead of directly calling the
transport/adapter with partial options:

```
wp design-core figma_prepare '<FIGMA_URL>'
wp design-core figma_compile '<FIGMA_URL>'
wp design-core figma_build '<FIGMA_URL>' --verify=1
wp design-core figma_verify '<FIGMA_URL>' '<CANDIDATE_URL>' --page-id=<ID>
```

The strict path always requests image fills, vector assets and a Figma-rendered
reference. If source evidence cannot be obtained, fail closed; do not silently
replace icons, omit assets or guess a different design.

A persisted Elementor tree is NOT completion. For a Figma task, completion
requires rendered verification against the Figma reference. If verification
reports `needs-correction`, inspect its exact Figma-node/Elementor-owner evidence,
correct the generic compiler/runtime issue, render again and repeat.

## Design Memory contract

Design Memory exists to prevent verified failures from recurring. Agents MUST
consult it before diagnosing or changing a source pattern:

```
wp design-core design_memory
wp design-core design_memory_incidents
wp design-core design_memory_benchmarks
```

Learning rules:

- Failed renders create incidents, not lessons.
- A lesson may be recorded only after measured rendered verification passes.
- Runtime learning is project/source scoped; it must never create a new global
  compiler rule by itself.
- Learned strategies must already exist in `Fidelity_Rule_Registry`; memory may
  not inject arbitrary PHP, HTML, CSS, Elementor settings or shell commands.
- Raw prompts, screenshots and customer source documents must not be stored in
  Design Memory. Keep only bounded signatures, strategies and verification
  summaries.
- Repeated high-confidence verified lessons may become benchmark candidates;
  promoting a candidate into a committed regression fixture still requires a
  reviewed deterministic test.
- Never weaken or delete a lesson/test just to make a later design pass. If a
  rule becomes obsolete because Figma/Elementor changed, supersede/version it
  with evidence.

The desired loop is:

```
source
  -> retrieve verified lessons
  -> compile
  -> render
  -> compare
  -> fail: incident + correction
  -> render again
  -> pass: reinforce verified lesson
  -> repeated lesson: benchmark candidate
```
