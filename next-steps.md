# Design Core Elementor 1.0.0-rc22

rc22 is the local AI design-engine release. It restores the design-intelligence and rendered-fidelity layers and connects them to the current Elementor compiler/runtime without requiring MCP or remote-control infrastructure.

## Implemented

- Design Intelligence v2 with 50+ real-world vertical profiles, including dedicated veterinary and medical families.
- Design Brief parser and complete-site Page Strategy engine.
- Local Design Agent service for Hermes, OpenCode, WP-CLI and direct PHP callers.
- Page Shell library v2 with 46 page archetypes while keeping legacy shell IDs/schema compatibility.
- Section Recipe library v2 with 60+ reusable section families while keeping legacy recipes.
- Restored screenshot capture, visual regression, Visual Feedback v3, UX audit, benchmark corpus and governed V3 visual correction.
- Functional `Visual_QA::compare_targets()`.
- Independent Visual Quality Gate: required visual/interaction evidence cannot silently pass while unverified.
- Structural Diff Engine: large composition errors escalate to BuildPlan rebuild instead of arbitrary CSS patches.
- Task Intelligence v3 consumes Design Brain output before widget selection.
- CI no longer depends on deleted MCP/remote directories and includes Design Brain + browser fidelity contracts.

## Next priorities

1. Expand the benchmark corpus to 50-100 real reference websites/Figma frames across supported verticals.
2. Improve cross-markup alignment using semantic anchors, section boundaries, relative geometry and Page Manifest mappings.
3. Expand automatic correction only for controls verified by the live Elementor runtime schema.
4. Add a governed BuildPlan patch operation to rebuild only structurally failed sections while preserving unaffected content.
5. Retain fresh runtime evidence against each supported WordPress/Elementor matrix before calling a release production-ready.
6. Profile large sites before adding storage/indexing architecture.

## Release rule

Syntax, architecture, source-atom fidelity and native-control validation are not visual proof. Reference-driven work is complete only after the rendered candidate passes the required multi-viewport quality gate.
