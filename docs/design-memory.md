# Design Memory v1 (RC24)

Design Memory is a persistent, bounded learning layer for Design Core Elementor.
Its purpose is to make a verified fidelity fix useful on the next design without
letting an AI agent train the compiler on an unverified guess.

## Why it exists

A design compiler can repeatedly make the same class of mistake even after one
page was manually corrected: a Figma SVG becomes a generic container, a small
primitive stretches in flex layout, a mixed-style heading is split into several
widgets, or a bottom-anchored card loses its owner. A one-off patch fixes a page;
a verified lesson plus a regression candidate prevents the failure class from
quietly returning.

RC24 therefore makes the normal loop:

```text
source
  -> source signals
  -> retrieve verified lessons
  -> governed fidelity rules
  -> Design IR
  -> Elementor
  -> browser render
  -> exact Figma-node / screenshot verification
  -> FAIL: incident + correction
  -> PASS: verified lesson reinforcement
  -> repeated strong lesson: benchmark candidate
```

## Three scopes

- `global`: reviewed compiler rules shipped by Design Core. Runtime learning does
  not create global rules.
- `project`: patterns verified on the current WordPress site/project.
- `source`: patterns verified for one source fingerprint, such as a Figma file +
  selected node.

More specific lessons receive a retrieval bonus but still have to match source
signals and the minimum confidence threshold.

## Safety model

The memory option contains compact metadata only. It does not persist raw
prompts, screenshots, Figma documents or customer HTML. A lesson contains a
failure signature, one allow-listed strategy, scope, confidence, verification
counts and bounded numeric/status evidence.

The `Fidelity_Rule_Registry` is the hard safety boundary. Memory cannot invent a
new mutation strategy or inject arbitrary CSS/PHP/HTML/Elementor settings. A new
strategy has to be implemented and reviewed in source code first.

Failed verification never becomes a lesson. It becomes an incident. Only a
rendered PASS can reinforce a lesson. This avoids the feedback-loop failure where
an agent guesses a fix, stores the guess, and makes future output worse.

## Seeded fidelity knowledge

RC24 ships reviewed global lessons for these already-observed classes:

- preserve authored Figma vector assets;
- preserve icon-bearing component/button composition;
- lock small primitives/icons against flex stretching;
- preserve authored bottom anchors;
- preserve mixed-style rich heading composition;
- preserve Figma FILL flex behavior;
- preserve fixed media height;
- verify exact Figma node geometry against its rendered Elementor owner.

These are generic rules. No PawCare node ID, text or customer-specific value is
hardcoded into production behavior.

## Failure signatures

`Failure_Signature_Engine` converts source and QA evidence into stable names such
as:

```text
figma.vector-component.asset
figma.button.icon-composition
figma.small-primitive.fixed-size
figma.absolute.bottom-anchor
figma.mixed-text.composition
figma.auto-layout.fill
figma.media.fixed-height
figma.rendered-node.geometry
```

A signature is deliberately semantic rather than page-specific. That is what
allows one verified incident to help another design with the same failure class.

## Exact Figma verification

`Figma_Geometry_Verifier` uses the `dc-figma-node-*` class emitted by the compiler
to match a source node to the exact rendered Elementor owner. It does not rely on
text order or a DOM index. Figma canvas coordinates are normalized relative to
the selected root before comparing x/y/width/height.

The strict Figma service now combines this geometry evidence with screenshot
comparison. This lets feedback say which Figma node is wrong, which Elementor
owner rendered it, and how its geometry differs.

## Strict Figma entry points

Figma URL work should use these WP-CLI commands:

```bash
wp design-core figma_prepare 'https://www.figma.com/design/...?...node-id=...'
wp design-core figma_compile 'https://www.figma.com/design/...?...node-id=...'
wp design-core figma_build 'https://www.figma.com/design/...?...node-id=...' --verify=1
wp design-core figma_verify 'https://www.figma.com/design/...?...node-id=...' 'https://site.test/page/' --page-id=123
```

`figma_url` remains as a compatibility command but now routes through strict
prepare as well. Strict prepare always requests image fills, vector exports and a
Figma-rendered reference. Missing reference evidence is an error rather than a
silent degraded conversion.

## Memory inspection

```bash
wp design-core design_memory
wp design-core design_memory --scope=source
wp design-core design_memory_incidents
wp design-core design_memory_benchmarks
```

Agent Gateway v3 exposes equivalent read/preview surfaces plus strict Figma
prepare/compile/build/verify operations.

## Benchmark promotion

A high-confidence verified lesson seen repeatedly is copied into the persistent
benchmark-candidate catalog. This is intentionally not automatic source-code
mutation. A developer/agent still has to turn a useful candidate into a small,
deterministic committed fixture and run full Local CI. This keeps the test suite
reviewable and prevents generated tests from simply encoding a bad output.

## Validation

The deterministic contract is:

```bash
php tests/design-memory/run.php
```

The authoritative project gate remains:

```bash
./scripts/ci-local.sh
```

The full gate covers PHP compatibility, existing contracts, browser tooling,
responsive analysis, screenshot regression and the PawCare benchmark. A change
must not be declared complete until that full local gate passes.
