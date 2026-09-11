# Contracts

This document describes the canonical runtime contracts used by Design Core Elementor `1.0.0-rc17`.

## Design IR v4

Design IR is the platform-neutral source of truth before BuildPlan/Elementor mapping.

Required top-level fields include:

```text
schema_version = 4
type = design-ir
nodes = []
root_ids = []
```

`section_root_ids` is an optional normalization-time field. It identifies non-overlapping reusable section boundaries and is distinct from `root_ids`, which remain BuildPlan/document roots.

A Design IR node contains platform-neutral fields such as:

- source
- semantic
- content
- layout
- style
- spacing
- responsive
- assets
- interaction
- component
- children

Section Intelligence may add a platform-neutral `section` metadata block to detected section nodes.

Elementor fields such as `elType`, `widgetType`, Elementor `settings`, and `__globals__` are forbidden inside canonical Design IR.

## Component Fingerprint v2

Component fingerprint v2 remains the compatibility contract for Component/Widget Registry items.

Required fields:

```text
version = 2
semantic: string
structure: string
content_schema: array
layout: string
interaction: string
```

Component presentation variants additionally persist an asset-neutral `presentation_hash` and may persist their own blueprint.

## Section Fingerprint v3

Section Registry items use fingerprint version `3`.

Required fields:

```text
version = 3
semantic: string
topology: string
slots: array
layout_topology: string
responsive_topology: array
media_topology: array
interaction_topology: array
family_hash: string
variant_hash: string
```

`detail_hash` is an optional rc17 refinement used by current writers/readers for strict full-subtree presentation matching. It remains optional so early v3 registry data can be loaded and migrated conservatively.

### Family vs presentation

- `family_hash` answers whether two sections share the same exact structural family contract.
- `variant_hash` is retained for original v3 presentation compatibility.
- `detail_hash` is the preferred strict presentation identity for new records.

Asset URLs/attachment IDs do not participate in presentation identity. Non-asset CSS surrounding `url(...)` still participates.

## RegistryItem schema v2

Registry item schema version remains `2`.

Supported `type` values:

```text
component
widget
section
```

Common required fields:

```text
id
type
schema_version
item_version
created_at
updated_at
source
usage
fingerprint
```

Fingerprint validation depends on registry item type:

- component/widget → Component Fingerprint v2
- section → Section Fingerprint v3

Section usage locations may be structured records containing page/position/root information. Component/widget registries retain legacy scalar location compatibility where appropriate.

## Section master

A Section Registry master contains at least:

```text
fingerprint
master.blueprint
master.editable_schema
variants
instances
usage
```

### Section variants

A section variant contains:

```text
fingerprint: Section Fingerprint v3
blueprint: array
family_relation?: array
```

A variant whose `family_hash` differs from the master must carry a verified compatible-family relation.

The registry recomputes compatibility during validation/import. `family_relation.score` must match the recomputed score; imported metadata cannot grant itself compatibility.

### Section instances

A section instance contains:

```text
id
master_id
bindings
assets
allowed_overrides
location
```

Typical location:

```php
array(
    'page_id' => 42,
    'position' => 2,
    'root_id' => 'node-abc123',
)
```

## Component variants and instances

Component variants may contain:

```text
fingerprint
presentation_hash
blueprint
```

Legacy component variant definitions remain readable even if their nested fingerprint predates the current normalized shape.

Component instance identity includes:

- master ID;
- content bindings;
- asset bindings;
- allowed overrides;
- location.

Canonical conversion includes source `node_id` in allowed overrides so two visually/content-identical components in different positions on the same page remain separate instances.

## Section Boundary Detector contract

`Design_Core_Elementor_Section_Boundary_Detector` returns non-overlapping node IDs for page-level sections.

The detector does **not** replace Design IR `root_ids`.

Normalization stores its result as:

```text
section_root_ids: string[]
```

Structural ancestors of detected boundaries are page-composition containers, not component masters. Normalization disables component reuse ownership on those ancestors.

## Section classification

Section Registry classification returns:

```text
action: new | reuse | variant
item: registry item | null
score: float
reason: string
variant_id: string
family_relation?: array
```

Exact family hash match is attempted first.

If no exact family exists, compatible-family matching is conservative and requires:

- exact semantic family;
- exact interaction topology;
- topology similarity floor;
- slot similarity floor;
- layout similarity floor;
- weighted score >= registry threshold.

A compatible-family match produces `variant`, never implicit exact master reuse.

## BuildPlan v1

Required top-level fields:

```text
build_plan_schema_version = 1
items = []
diagnostics = []
```

There is one BuildPlan item per Design IR `root_id`.

A BuildPlan item contains:

```text
node_id
component_id
strategy
adapter_target
reuse
variant
content_bindings
style_bindings
responsive_bindings
asset_bindings
fallback
diagnostics
```

Supported strategies include:

```text
native-widget
native-compose
reuse-component
reuse-widget
component
variant
loop
custom-widget
css-fallback
raw-html-fallback
```

Section reuse currently uses the same IR-driven reuse/variant executors as component reuse because the correct section master/variant blueprint has already been applied by Section Intelligence before BuildPlan execution. Registry type remains available in diagnostics/reuse metadata.

## Page Manifest v1

Manifest schema:

```text
schema_version = 1
page_id: int
title: string
sections: []
```

Each section record contains:

```text
position
root_id
family
action
master_id
variant_id
score
reason
```

Allowed actions:

```text
new
reuse
variant
```

`Page_Manifest::from_ir()` is suitable for pre-sync/introspection views.

`Page_Manifest::from_sync()` is authoritative for persisted conversion results because Section Registry state can change while processing earlier sections on the same page.

Persisted post meta:

```text
_design_core_page_manifest
```

## Registry export/import

Current export format:

```text
format = design-core-registry
schema_version = 4
components
sections
widgets
tokens
```

Import accepts schema v3 and v4.

Legacy v3 payloads contain no sections. A v3 import preserves the existing Section Registry rather than deleting it.

All registry payload sections are validated before replacement. Cross-family section compatibility is recomputed during validation.

## Registry mutation and locking

Versioned registries use lock/verification semantics for writes.

Conversion transactions keep:

- pre-conversion registry snapshots;
- expected post-mutation registry state.

Rollback restores a registry only when its current value still equals the transaction's expected post-mutation value. This is the conflict-aware rollback contract.

Tracked registries include:

- components
- sections
- widgets
- tokens where applicable.

Activation migration backup/rollback includes components, sections, widgets and tokens.

## Section Recipe contract

`Design_Core_Elementor_Section_Recipe_Compiler` accepts a platform-neutral recipe and page bindings and emits validated Design IR v4.

Recipe fields may include:

```text
family
variant
tag
classes
layout
style
spacing
responsive
interaction
slots
```

Supported slot types include:

```text
heading
rich_text
link
media
list
form
text
```

Required slots fail closed when bindings are missing.

Recipes cannot contain Elementor-specific storage fields.

## Adapter contract

Elementor adapters implement the common target/save/reload/render boundary.

V3 is the stable storage path.

V4/Atomic save is capability-gated and requires a governed public composition transformer. The adapter never fabricates private Atomic storage.

Elementor Pro Loop is similarly capability-gated and requires a governed integration instead of guessed private storage.

## Observability stages

Canonical conversion can emit stages including:

```text
preflight
analysis
ir-validation
normalization
reuse-search
decision
planning
execution
globals
save
reload
render
structural-qa
registry-sync
section-registry-sync
commit
```

Fallback diagnostics record requested/actual strategy and reason when a governed fallback path is used.

## Architecture vs runtime evidence

Architecture contracts and class/capability checks do not prove runtime success.

Production readiness separately evaluates required runtime evidence. CI files, tests and QA tooling are not evidence for a specific commit until actually executed in the target environment.
