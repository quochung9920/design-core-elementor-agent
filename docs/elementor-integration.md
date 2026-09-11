# Elementor Integration

This document defines the boundary between platform-neutral Design Core data and Elementor runtime data.

## Boundary rule

Canonical Design IR must never contain Elementor storage keys such as:

- `elType`
- `widgetType`
- `settings`
- `__globals__`

Only Elementor adapters and widget-control adapters may create those fields.

## Runtime control discovery

`Control_Schema_Registry` reads controls from the active Elementor runtime instead of using a static guessed list. It combines:

- element controls;
- widget controls;
- optimized `style_controls`;
- applicable common widget controls.

A control is mapped only when its runtime type is compatible with the canonical value. A similar control name alone is not sufficient.

The registry caches discovered schemas for the current process. If the active stack cannot expose a reliable schema, native mapping fails closed.

## Widget-specific adapters

Dedicated control adapters exist for:

- Container
- Heading
- Text Editor
- Button
- Image

Each adapter owns the mapping from canonical semantic intent to actual runtime control IDs. Shared value encoding covers dimensions, dimensions with sides, colors, typography, links, media, responsive suffixes, and switchers.

### Container ownership

Containers own structural layout:

- display and flex direction;
- wrap, alignment, and justification;
- responsive gap;
- width/min/max dimensions;
- padding and genuine structural offsets;
- background and border controls;
- semantic `html_tag`;
- responsive visibility when a composition intentionally changes representation.

Parent Container Gap is the preferred owner of spacing between sibling widgets. Child widget margins remain unset unless a documented offset cannot be represented by structure, padding, alignment, or Gap.

### Heading and text

Heading/Text Editor adapters own editable content and typography. Typography values are mapped with runtime-compatible units and responsive settings.

A unitless source line-height may be encoded into an equivalent native unit only when generated CSS proves it remains effective. Stored JSON alone is not sufficient evidence.

### Button

Button mapping owns text, link, alignment, typography, text/background colors, border, radius, padding, and supported hover state controls.

### Image

Image mapping resolves local URLs to Media Library attachment IDs. Width, alignment, object fit, object position, and responsive media controls are separate concerns and must be mapped independently.

Decorative images should have empty alt text; meaningful images preserve source alt text.

## Native-first style policy

The mapping order is:

```text
native runtime control
→ owner-scoped Custom CSS for an unsupported declaration
→ explicit unsupported diagnostic
```

Custom CSS may be used only on the element that owns the declaration. It must use Elementor's `selector` scope. The plugin never creates a stylesheet inside an HTML widget.

Governed fallback examples include an unsupported mask, pseudo-element geometry, browser-specific text effect, or relational interaction. Standard padding, margin, typography, colors, backgrounds, supported flex/grid layout, and supported responsive controls are not valid fallback reasons.

## Scoped CSS safety

`Scoped_CSS_Fallback` rejects:

- selectors that escape owner scope;
- external or data URLs;
- `@import`;
- script schemes;
- CSS escapes and control characters;
- legacy `expression()` behavior;
- ungoverned resource constructs;
- malformed property names or values.

The architecture auditor reports every Custom CSS owner and blocks stylesheet HTML.

## Elementor V3 adapter

`Elementor_V3_Adapter` is the sole owner of V3 element JSON, controls, persistence, reload, and render.

Responsibilities include:

- mapping canonical subtrees to runtime-validated native controls;
- recursively validating Elementor element shape;
- saving through Elementor Document API;
- decoding stored Elementor data after save;
- rendering through Elementor frontend APIs;
- regenerating/validating generated CSS in runtime smoke paths;
- recording clear errors when the lifecycle is unavailable.

Direct `_elementor_data` updates are not a conversion fallback.

## Elementor V4 / Atomic adapter

`Elementor_V4_Adapter` extends V3 behavior only through public Elementor abilities:

- `elementor/build-composition`
- `elementor/manage-classes`

It does not assume undocumented Atomic storage. Unsupported structures downgrade to V3 with a persisted reason. The calling service must use the actual adapter for reload and render after downgrade.

Public API detection is capability information, not proof of a Design Core save/reload/render round trip.

## Native widget execution

The Decision Engine may choose `native-widget` for semantic roles such as FAQ. Before constructing a widget:

1. `Native_Widget_Resolver` checks ordered candidates for the semantic role;
2. the widget must be registered in the active runtime;
3. required content controls must actually exist;
4. Pro upsell placeholders without real controls are rejected;
5. `Native_Widget_Binder` binds canonical content without re-reading HTML.

If no functional candidate exists, the item falls back explicitly to `native-compose` with diagnostics.

## Loop integration

The generic Loop executor does not fabricate Elementor Pro internals. `Pro_Loop_Adapter` defines governed integration stages:

- supports
- preflight
- create loop template
- create query binding
- create loop grid
- validate
- rollback

A site-specific integration must provide real public/runtime behavior through these stages. Without it, Loop falls back to native composition and records the reason.

## Global colors and typography

`Global_Style_Bridge` synchronizes token values into the active Elementor design system.

V3 synchronization is additive:

- user-owned Kit entries remain untouched;
- Design Core-owned IDs remain stable across syncs;
- matching settings receive real `__globals__` references;
- a typography preset is bound only when all declared desktop and responsive properties match;
- local responsive overrides are never silently discarded to force a global binding.

Effective output requires a live Kit ID and generated CSS. A stored global reference by itself does not prove success.

## Global layout standard

`Global_Layout_Standard` owns the `dc-global-container` class and four tiers:

| Tier | Container width | Inline padding |
|---|---:|---|
| Desktop | 1440px | `clamp(32px, 4vw, 64px)` |
| Laptop | 1366px | `clamp(28px, 3.5vw, 48px)` |
| Tablet | 1024px | `clamp(20px, 3vw, 32px)` |
| Mobile | 767px | `clamp(16px, 4vw, 24px)` |

The active Kit receives native `container_width` where supported. Elementor may therefore emit `--container-max-width` on the common `.e-con` selector; that variable is global configuration, not proof that a particular Container is Boxed. Clamp-based responsive gutters are written into one marked, managed Kit Custom CSS block. Existing user CSS outside the markers is preserved.

Ownership rules:

- `content_width = full` is authoritative Full Width and may not carry a local `max-width` constraint;
- `content_width = boxed` is authoritative Boxed and inherits the native Kit Global Content Width;
- a full-bleed surface must not receive the global container class;
- a boxed surface or constrained child may own `dc-global-container` only for responsive inline gutters;
- `dc-global-container` must never set `width` or `max-width` and must not simulate Boxed behavior;
- unauthorized source ownership is stripped and invalid hierarchy fails validation.

## Responsive geometry rules

- Prefer native Full Width/Boxed content controls, width `100%`, flex/grid sizing, and global gutters.
- Large structural fixed widths require a semantic exception.
- Prefer content-driven height, intrinsic media size, aspect ratio, and native responsive min-height.
- Avoid fixed pixel section height and `100vh` by default.
- Use viewport units only for deliberate fullscreen or bounded hero behavior with clipping checks.
- Verify generated CSS and browser geometry at governed viewports.

## Architecture audit

`Elementor_Architecture_Auditor` checks generated elements for:

- stylesheet markup inside HTML widgets;
- unscoped or suspicious Custom CSS;
- `!important` usage;
- class-only containers with no native structural settings;
- native style-control coverage;
- invalid reserved global-container ownership.

Run the audit through the runtime smoke or page QA command; do not infer architecture quality from visual appearance alone.
