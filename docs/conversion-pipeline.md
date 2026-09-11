# Conversion Pipeline

This document describes the canonical conversion lifecycle in Design Core Elementor `1.0.0-rc17`.

## Overview

```text
Source HTML/CSS
   ↓
Analysis Engine
   ↓
Design IR v4
   ↓
IR validation
   ↓
Responsive normalization
   ↓
Section boundary detection
   ↓
Section Intelligence / Section Registry classify
   ↓
Exact component reuse for non-section descendants
   ↓
Optional remote asset import
   ↓
Build Planner / BuildPlan v1
   ↓
Strategy execution
   ↓
Global token/style synchronization
   ↓
Create Elementor page
   ↓
Save → reload → render
   ↓
Structural audit
   ↓
Component Registry synchronization
   ↓
Section Registry synchronization
   ↓
Page Manifest persistence
   ↓
Commit transaction
```

## 1. Analysis

`Design_Core_Elementor_Analysis_Engine` parses bounded HTML/CSS and produces source analysis plus canonical Design IR.

The analysis stage remains source-oriented. Registry reuse decisions do not happen while parsing markup.

Design IR nodes include source semantics, content, layout/style/spacing, responsive evidence, media/assets, interaction, component metadata and hierarchy references.

## 2. IR validation

`Design_Core_Elementor_Design_IR_Validator` validates Design IR v4 before normalization/reuse.

The validator enforces:

- supported schema version;
- unique node/root IDs;
- valid parent/child graph;
- platform neutrality;
- responsive-state/value contracts;
- layout/spacing/media governance.

## 3. Responsive normalization

`Design_Core_Elementor_Responsive_Normalizer` converts responsive node values into the canonical normalized shape used by section fingerprinting and Elementor control mapping.

This occurs before Section Intelligence so responsive presentation participates consistently in section identity.

## 4. Section boundary detection

`Design_Core_Elementor_Section_Boundary_Detector` finds non-overlapping page-level reuse boundaries.

These are stored as:

```text
section_root_ids
```

They are intentionally separate from Design IR `root_ids`.

Example:

```html
<main>
  <section class="hero">...</section>
  <section class="services">...</section>
  <section class="faq">...</section>
</main>
```

Possible normalized ownership:

```text
root_ids:
  - main

section_root_ids:
  - hero section
  - services section
  - faq section
```

BuildPlan can still compose the whole `<main>` as one document root while Section Registry learns/reuses the real page sections inside it.

Structural ancestors of detected boundaries are marked as section containers and have component reuse ownership disabled.

## 5. Section Intelligence

For each detected section boundary:

1. build Section Fingerprint v3;
2. query Section Registry;
3. classify NEW / REUSE / VARIANT;
4. if exact reuse matches a master/registered variant, apply its blueprint to a copy of current IR;
5. preserve instance-owned content/media/classes;
6. attach platform-neutral section metadata to the boundary node.

### Exact family

Exact `family_hash` match is preferred.

### Compatible family

If exact hash is absent, the registry can conservatively classify a structurally compatible same-semantic family as VARIANT. Compatibility is scored from topology/slots/layout/media with strict semantic/interaction requirements.

This prevents optional CTA/media differences from creating unnecessary duplicate section masters.

## 6. Component reuse

After section classification, `Conversion_Service::apply_registry_reuse()` processes reusable non-section component nodes.

Section roots are skipped because their lifecycle belongs to Section Registry.

For a component candidate:

1. find semantic/structure registry candidates;
2. run Variant Engine on canonical presentation evidence;
3. apply a component blueprint only for exact presentation reuse;
4. if a registered component variant matches, use that variant's blueprint;
5. preserve current instance assets/content.

Changing only an image URL/attachment does not create a component variant. Changing layout/style/spacing/responsive presentation does.

## 7. Asset import

When enabled, remote foreground/background media is imported after reuse decisions.

This ordering prevents local attachment IDs or rewritten local URLs from becoming reuse identity.

Imported media remains instance-owned.

## 8. Build Planner

BuildPlanner creates one BuildPlan item per Design IR `root_id`.

Important ownership rules:

- a BuildPlan root that is itself a section uses Section Registry metadata;
- a structural root containing nested `section_root_ids` is composed normally and cannot accidentally match Component/Widget Registry;
- arbitrary descendant component masters do not bubble to the root;
- a narrow single-direct-child thin-wrapper compatibility rule remains for legacy component reuse.

BuildPlan remains Elementor-targeted only at the adapter target/strategy level; canonical IR stays platform-neutral.

## 9. Strategy execution

BuildPlan executors operate on scoped Design IR subtrees.

Strategies include native widget/compose, reuse, variant, loop, custom widget and governed fallback strategies.

Section reuse does not require the executor to reload section storage because the selected section master/variant blueprint is already represented in scoped IR before execution.

Custom-widget execution may mutate Widget Registry. Conversion Transaction tracks this side effect so a later conversion failure can restore it conflict-safely.

## 10. Global design-system synchronization

The selected global adapter synchronizes managed design tokens/references with the target Elementor mode.

Kit/global mutations are transaction-tracked.

## 11. Create/save/reload/render

A page is created and saved through the selected Elementor adapter.

Canonical lifecycle requires successful save, reload and render evidence before registry synchronization is committed.

When capability-gated V4 falls back to V3, the actual runtime adapter is used for reload/render.

## 12. Structural audit

The existing structural audit stage can reject a converted page before registry learning is committed.

This stage is part of runtime lifecycle code. Static code review alone does not prove its runtime result.

## 13. Component Registry synchronization

Reusable non-section components are synchronized after the page successfully reaches the registry stage.

The synchronization can:

- create a component master;
- reuse a master;
- create/reuse a presentation variant;
- register a page/node-specific instance.

Component instance identity includes current content/assets/overrides/location and canonical conversion includes `node_id` to avoid same-page collisions.

## 14. Section Registry synchronization

Section boundaries are reclassified against the **current** Section Registry while synchronizing.

Reclassification matters when multiple related sections first appear on one page: an earlier boundary can create a master before a later boundary is processed.

Synchronization can:

- create a section master;
- register an exact section instance;
- persist a same-family presentation variant;
- persist a verified compatible-family variant;
- reuse a previously registered variant.

Section registry mutations are transaction-tracked.

## 15. Page Manifest

The persisted Page Manifest is built from actual Section Registry synchronization results, not from pre-sync predictions.

Post meta:

```text
_design_core_page_manifest
```

Manifest section records expose:

- order/position;
- source root ID;
- semantic family;
- NEW/REUSE/VARIANT action;
- master ID;
- variant ID;
- matcher score/reason.

## 16. Transaction commit/rollback

The transaction can restore:

- created posts;
- created attachments;
- component registry state;
- section registry state;
- widget registry state;
- managed Kit state.

Registry rollback is expected-state/conflict aware: it does not blindly overwrite a registry if another process changed it after this conversion's mutation.

## 17. Registry export/migration

Registry export format v4 contains:

- components;
- sections;
- widgets;
- tokens.

Legacy v3 import preserves existing Section Registry.

Activation migration backup/rollback includes Section Registry as a first-class registry.

## 18. Production readiness

Architecture checks cover Design IR, BuildPlan, section registry/fingerprint, Page Manifest, Section Recipe Compiler, security/rollback/observability and Elementor capabilities.

Runtime evidence remains separate from architecture. A workflow/test file existing in the repository is not proof that the current commit passed it.

## 19. Unified strict build pipeline

All mutation-capable flows (HTML conversion, approved-plan execution, agent
draft writes) share one enforcement chain; there is no second looser path:

```text
Source HTML/CSS
   ↓ responsive compiler (media queries → tablet/mobile IR overrides)
   ↓ semantic extraction (header/nav/tables/FAQ/steps/form/footer contracts)
   ↓ widget selection: HARD compatibility first (content + behavior +
      required controls + binding support), score only afterwards
   ↓ control binding governed per live runtime schema (conditions, units,
      responsive support; rich-text layout stripped to text; trivial and
      unrepresentable CSS dropped with diagnostics, never persisted)
   ↓ executor runs the shared strict structure gate over built elements
   ↓ preview reports crash-safety PLUS binding readiness, responsive mapping
      and CSS posture (it never claims fidelity it did not execute)
   ↓ approved draft write → reload → post-write gate over reloaded tree
```

`can_execute_safely` means "runs without crashing", NOT "artifact is
structurally correct". Structural correctness is reported separately by the
strict gate (`pass`/`fail` + material findings) and enforced before any
persistence.

## 20. Widget-selection contract

Decision order: semantic intent → content contract → behavior contract →
required controls → active conditions → responsive capability → actual binding
support → editability → score. A candidate that cannot bind its required data
controls (e.g. a menu widget without bindable menu items) is rejected before
any score comparison; a dedicated semantic binder owns data widgets, generic
binding never emits them.

## 21. Control-binding rules

Every persisted setting must name a control that exists in the live runtime
schema, is active for the effective settings, accepts the value shape and
unit, and (for responsive suffixes) is exposed per-device. Text settings must
not contain layout markup. `custom_css` is not a general fallback: trivial
declarations (`box-sizing`, `content`) and unrepresentable ones (side
borders, table internals, offsets, outlines, `white-space`, `font` shorthand)
are dropped with diagnostics. Remaining custom CSS fails the structural gate.

## 22. Responsive normalization

Source breakpoints classify to the closest ACTIVE Elementor device
(`tablet`/`mobile` by default); close queries merge with later source order
winning, desktop-identical values are skipped, and everything unmapped is
recorded in `diagnostics.responsive_mapping` — never silently downgraded.

## 23. Structural vs visual vs interaction QA

Structural QA (settings validity against the live runtime schema) gates
writes. Visual and interaction QA require a real browser runtime and stay
`not_verified` until screenshot/interaction comparison actually runs; they
are never faked to pass, and promotion stays blocked while they are missing.
