# Section Library and Page Manifests

Design Core Elementor rc17 adds a section-first reuse layer on top of the existing component/widget registries.

## Goals

- Build pages in sequence and reuse sections already created earlier.
- Distinguish exact reuse from a compatible visual variant.
- Keep page-specific content/media as instance bindings rather than duplicating shared structure.
- Keep Elementor storage out of the section contract; recipes and fingerprints remain platform-neutral.

## Canonical flow

```text
Design IR v4
  -> responsive normalization
  -> section intelligence
  -> Section Fingerprint v3
  -> Section Registry lookup
  -> NEW / REUSE / VARIANT
  -> exact component reuse only
  -> BuildPlan
  -> Elementor adapter
  -> component/section registry synchronization
  -> Page Manifest v1
```

Section classification happens before component blueprint application so a component master cannot accidentally change the source presentation before section identity is calculated.

## Section Fingerprint v3

A section fingerprint separates family identity from variant identity.

Family identity includes:

- semantic family;
- bounded subtree topology;
- named slot/cardinality schema;
- layout topology;
- responsive-state topology;
- media topology;
- interaction/dynamic topology.

`family_hash` answers: "Is this the same section family?"

`variant_hash` additionally includes surface/layout/style/spacing/responsive values and answers: "Is this the same concrete presentation?"

Exact family + master variant match => `reuse`.

Exact family + a previously registered variant match => `reuse` using that variant blueprint.

Same family + unregistered presentation => `variant`.

No family match => `new`.

## Section Registry

WordPress option: `design_core_elementor_sections`.

Each section master stores:

- RegistryItem metadata/versioning;
- Section Fingerprint v3;
- platform-neutral recursive blueprint;
- editable named-slot schema;
- variants;
- instances and usage locations.

A section instance stores named bindings and assets separately from the shared master.

## Named slots

Section Intelligence derives editable slots in source order, for example:

```text
heading_1
rich_text_1
link_1
media_1
list_1
form_1
```

This prevents page content from becoming part of section family identity.

## Page Manifest v1

A generated page exposes a compact section map:

```yaml
schema_version: 1
page_id: 42
sections:
  - position: 0
    root_id: node-hero
    family: hero
    action: reuse
    master_id: section-hero-a1b2c3d4e5
  - position: 1
    root_id: node-coverage
    family: locations
    action: new
```

The canonical `Design_Core_Elementor_Conversion_Service` synchronizes the Section Registry and persists the manifest to `_design_core_page_manifest` before the conversion transaction commits. `Design_Core_Elementor_HTML_Converter` is only a compatibility facade.

## Section Recipe Compiler

`Design_Core_Elementor_Section_Recipe_Compiler` accepts a declarative recipe and page-specific bindings, then emits Design IR v4. Recipes are intentionally Elementor-neutral.

Example concept:

```php
$recipe = array(
    'family' => 'hero',
    'tag' => 'section',
    'classes' => array( 'dc-service-hero' ),
    'layout' => array( 'display' => 'flex', 'direction' => 'row' ),
    'slots' => array(
        'heading' => array( 'type' => 'heading', 'tag' => 'h1', 'required' => true ),
        'lead' => array( 'type' => 'rich_text' ),
        'image' => array( 'type' => 'media' ),
        'primary_cta' => array( 'type' => 'link' ),
    ),
);

$ir = ( new Design_Core_Elementor_Section_Recipe_Compiler() )->compile( $recipe, $bindings );
```

This is the preferred migration path for large page-specific provisioning scripts: page files should eventually contain recipes + bindings rather than direct Elementor JSON/settings.

## Planner ownership rule

A master found deep inside a section is not allowed to make the entire root reusable. Root reuse requires:

- a section master owned by the root; or
- a component master owned by the root; or
- one direct child master when the root is only a single-child thin wrapper.

This prevents a reused card inside a mixed section from incorrectly forcing reuse of the whole section.

## Component compatibility rule

A component with the same semantic role and structure is no longer assumed to be exact reuse. Layout, shared style, spacing, responsive state, and media deltas are evaluated first. Only exact reuse may apply the existing component blueprint; presentation differences remain source-owned and are registered as variants.

## Registry transaction behavior

Component and Section Registry mutations are tracked after each synchronization stage. If a later registry stage fails, rollback restores the registry snapshots captured at conversion start, provided no unrelated concurrent write changed the same option.

## Compatibility

Component Registry remains schema v2 / fingerprint v2 for backward compatibility. Section Registry also uses RegistryItem schema v2, but its fingerprint discriminator is v3 and is validated separately.

Registry export format is v4. Import accepts both legacy export format v3 and v4. Importing a legacy v3 export preserves the current Section Registry instead of clearing section masters that did not exist in the old format.
