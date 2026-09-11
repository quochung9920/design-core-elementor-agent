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
