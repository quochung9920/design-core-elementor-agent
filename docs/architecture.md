# Architecture

## Purpose

Design Core Elementor is a compiler-style design intelligence layer. Source HTML/CSS/browser evidence is converted into platform-neutral Design IR, normalized, classified into reusable section/component families, planned through BuildPlan, and only then mapped to Elementor.

The architecture deliberately keeps website-level section identity separate from Elementor storage and from lower-level reusable components.

## Canonical pipeline

```text
HTML / CSS / browser evidence
        ↓
Analysis Engine
        ↓
Design IR v4
        ↓
Responsive Normalizer
        ↓
Section Boundary Detector
        ↓
Section Intelligence
        ↓
Section Fingerprint v3
        ↓
Section Registry
        ↓
Component exact-reuse / variants for non-section descendants
        ↓
Build Planner / BuildPlan v1
        ↓
Strategy Executors
        ↓
Elementor V3 / capability-gated V4 adapters
        ↓
Save → reload → render
        ↓
Component Registry sync
        ↓
Section Registry sync
        ↓
Page Manifest v1
```

## Two root concepts

Design IR now has two intentionally different root concepts.

### `root_ids`

These are document/build roots. BuildPlan creates one executable item per `root_id` so the Elementor document structure stays faithful to the source document.

### `section_root_ids`

These are non-overlapping page-level reuse boundaries discovered by `Design_Core_Elementor_Section_Boundary_Detector`.

A common source may be:

```html
<main>
  <section class="hero">...</section>
  <section class="services">...</section>
  <section class="faq">...</section>
</main>
```

Here `main` can remain a BuildPlan root while the three child sections become `section_root_ids`.

This prevents the registry from learning one giant page wrapper as the reusable unit.

## Section boundary rules

The detector prefers explicit `<section>` elements and recognized page-section semantics/classes. It can also recognize suitable article/nav/aside or semantic div boundaries.

Boundaries are non-overlapping: once a page-level section is selected, nested card/subsection nodes remain owned by that nearest section master rather than receiving competing section blueprints.

Document wrappers can contain section boundaries without becoming component masters. The normalization pipeline marks non-boundary ancestors as structural section containers and disables component reuse ownership on those wrappers.

Header/footer are not intended as normal page-section masters; site chrome should remain governed through Theme Part / dedicated site-chrome mechanisms rather than normal content-section reuse.

## Section ownership

Section roots own page-level reusable presentation.

A section root:

- is classified by Section Registry;
- can be NEW, REUSE or VARIANT;
- may receive a master/variant blueprint before mapping;
- is synchronized as a section instance after successful conversion;
- is not also synchronized as a component master.

Component Registry still operates on reusable descendants inside sections. This creates a clear hierarchy:

```text
Page document root
  → Section master/variant
      → Component master/variant
          → Elementor primitive/widgets
```

## Section Fingerprint v3

Section family identity and presentation identity are separated.

### Family identity

`family_hash` includes:

- semantic family;
- structural topology;
- slot/cardinality schema;
- layout topology;
- responsive topology;
- media topology;
- interaction/dynamic topology.

### Presentation identity

`variant_hash` remains for rc17 compatibility.

`detail_hash` is the strict full-subtree presentation discriminator used for new exact matches. It includes descendant layout/style/spacing/responsive presentation but neutralizes asset URL/attachment identity.

Changing an image does not create a variant. Changing typography, spacing, overlay, layout or responsive presentation does.

## Compatible section families

Exact `family_hash` match is preferred.

When an optional structural difference changes family hash, Section Registry can conservatively reuse the same family master as a variant if semantic and interaction contracts match and topology/slot/layout/media similarity passes guarded thresholds.

Compatible-family relations are recomputed by the registry on write/import. A payload cannot simply claim compatibility with a fabricated score.

## Blueprint ownership

A blueprint contains shared presentation/structure, not page-specific content.

Blueprint application preserves instance-owned data:

- text/rich text/link/list/form bindings stay in current IR content;
- foreground media stays in content bindings;
- background media stays instance-owned;
- source instance classes are preserved for section reuse;
- root tags and recursive topology must remain compatible before a blueprint is applied.

Component exact reuse follows the same content/asset ownership principle and registered component variants can supply their own blueprint.

## Page Manifest

After Section Registry synchronization, the conversion service creates Page Manifest v1 from the **actual synchronization results**.

This is important because registry state may change while processing earlier sections on the same page.

Manifest fields include:

- position;
- root_id;
- family;
- NEW/REUSE/VARIANT action;
- master_id;
- variant_id;
- score/reason.

The persisted post meta key is:

```text
_design_core_page_manifest
```

## Component Registry

Component Registry remains RegistryItem schema v2 / Component Fingerprint v2 for backward compatibility.

Component variant presentation identity is calculated from canonical layout/style/spacing/responsive/media evidence. Registered variants persist a presentation hash and their own blueprint.

Component instances include content, asset bindings, allowed overrides and source node identity so identical same-page instances do not overwrite one another.

## Registry persistence

Current registries are WordPress-option backed:

- `design_core_elementor_components`
- `design_core_elementor_sections`
- `design_core_elementor_widgets`
- design-token storage

Registry writes use the versioned-registry lock/verification path. Conversion rollback tracks expected post-mutation state to avoid overwriting unrelated concurrent writes.

Activation registry migration backup/rollback includes components, sections, widgets and tokens.

Registry export format v4 includes sections. Legacy v3 import preserves the current Section Registry because v3 had no section payload.

## BuildPlan ownership

BuildPlanner creates one item per document `root_id`, not per `section_root_id`.

If a BuildPlan root is itself a section, Section Registry metadata drives its reuse/variant decision.

If a BuildPlan root is a structural wrapper containing nested `section_root_ids`, it is forced through normal composition rather than matching Component/Widget Registry. The nested section blueprints are already present in scoped Design IR.

Component master ownership does not bubble arbitrarily from descendants. A root can use its own master, with a narrow compatibility exception for legacy thin wrappers containing exactly one direct component child.

## Elementor boundary

Design IR must remain Elementor-neutral. Fields such as `elType`, `widgetType`, Elementor settings and global-reference storage belong only after BuildPlan at the mapper/adapter boundary.

V3 is the stable path.

V4/Atomic is capability-gated and may fall back to V3 when a governed public composition transformer/reload/render integration is unavailable.

Elementor Pro Loop is also capability-gated and never guesses private Loop storage.

## Transactions

A conversion transaction can track:

- created pages;
- imported attachments;
- component registry mutations;
- section registry mutations;
- widget registry mutations created during strategy execution;
- token/global Kit changes where applicable.

A later failure can roll back only when the expected post-mutation state still matches, preventing blind overwrite of concurrent registry changes.

## Runtime evidence vs architecture

Architecture checks prove code/contracts are loaded. They are not runtime evidence.

`Production_Readiness` separately evaluates architecture/capability checks and environment-scoped evidence. The presence of test files, CI workflows or QA tooling is never by itself proof that the current commit passed those gates.
