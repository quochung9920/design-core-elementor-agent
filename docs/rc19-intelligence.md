# rc19 Intelligence and Control Plane

Design Core Elementor rc19 adds a set of services around the existing Design IR → BuildPlan → Elementor pipeline. These services are deliberately layered so admin, REST, CLI and optional WordPress Abilities all call the same core logic.

## Safety boundaries

- Design IR remains platform-neutral.
- Build Preview never executes a BuildPlan and never creates/mutates posts or registries.
- Runtime control discovery is authoritative; semantic widget metadata is secondary.
- Elementor V3 persistence uses the native Document API and verifies the result.
- Elementor V4 requires a public build-composition ability and governed transform/reload/render integrations.
- Private Atomic/Pro storage is never invented.
- Remote visual targets are URL-validated; local targets are constrained to WordPress/plugin/upload/temp roots.
- Destructive agent tools require `manage_options` plus an explicit confirmation argument.
- Persistent rollback is conflict-aware and refuses to overwrite a document changed after the recorded mutation.

## Build Preview

`Design_Core_Elementor_Build_Plan_Preview` accepts Design IR or HTML/CSS and returns planning evidence only. It validates and normalizes IR, runs the canonical planner, counts strategies/section decisions, derives runtime requirements, attaches live widget/control evidence where relevant and estimates element count.

## Persistence

`Design_Core_Elementor_Persistence_Service` wraps a raw native save callback and performs cache invalidation, reload, render and storage verification. It records runtime evidence and a Change Ledger entry only after a verified mutation.

Theme Part provisioning uses the same persistence service with a Theme-Builder-specific raw save callback so document type metadata is not rewritten as a normal page.

## Visual feedback

`Design_Core_Elementor_Visual_Feedback_Engine` combines viewport screenshot comparison with optional browser-analysis geometry/style comparison. It categorizes render, geometry, typography, media and general visual differences, then emits a native-control-first correction plan. Applying those directives remains a governed integration hook rather than an unverified write path.

## Layout intelligence

`Design_Core_Elementor_Layout_Intelligence` interprets structural Design IR nodes using canonical layout data and browser geometry mapped by DOM path. It reports common patterns such as full-bleed/inner, split ratios, grids, stacks and overlaps for downstream planning and diagnostics.

## Recipes and Page Shells

The Section Recipe Library stores reusable, Elementor-neutral production patterns. Required slot bindings are checked before invoking the existing Section Recipe Compiler. Page Shells provide ordered page-level patterns built from recipes, while Section Registry still owns section reuse/variant decisions.

## Figma adapter

The Figma adapter only converts supplied Figma JSON. Authentication, Figma API calls and image download/resolution belong in a separate transport/asset boundary. Figma image references therefore remain unresolved evidence until a governed resolver maps them to assets.

## Page Snapshot

Page Snapshot is a read model, not a new source of truth. It combines current post metadata, Page Manifest, Section explanations, Elementor tree statistics, runtime widget profiles, content outline, design tokens, responsive overrides, visual QA/feedback and metadata-only history.

## Change Ledger

Change Ledger is separate from conversion transactions:

- transaction rollback handles an in-progress failed conversion;
- Change Ledger handles an already completed mutation later.

Large before/after values are written into `uploads/design-core-history/` and the option record stores only a relative descriptor. Automatic rollback currently targets verified V3 Elementor saves; Atomic history remains audit-only until a public restore contract exists.

## Agent gateway

The compact gateway exposes internal tools such as build preview, snapshot, widget candidates, section explanation, page-shell compilation, Figma conversion, visual feedback, governed HTML conversion and history operations.

When WordPress provides the public Abilities API, Design Core registers only three meta abilities:

- `design-core/list-tools`
- `design-core/get-tool-schema`
- `design-core/call-tool`

This keeps tool discovery compact and prevents an MCP/agent surface from becoming a second implementation of Design Core behavior.
