# Fallback Policy

Decision order:

```text
Global Design System
→ Existing reusable component
→ Variant/extension of an existing component
→ Native Elementor controls
→ Native widget
→ Native composition
→ Reusable component
→ Scoped CSS
→ Custom widget
→ Raw HTML
```

Fallback is never exception swallowing. A fallback result must record:

- requested strategy;
- actual strategy;
- reason;
- error code;
- affected node/property;
- alternatives checked where applicable.

`padding`, `margin`, typography, colors, backgrounds, standard flex/grid layout, and supported responsive controls are not valid CSS-fallback reasons. CSS is reserved for unsupported pseudo-element geometry, masks, browser-specific text effects, or relational interactions.

Raw HTML is the final option and requires security approval plus evidence that native, composition, reuse, scoped CSS, and custom-widget alternatives were checked. Unexplained raw HTML is a release failure.

Elementor V4 may downgrade to V3 only when a public ability or verified responsive transformer is unavailable. The V4 adapter persists the exact downgrade reason; it never writes guessed Atomic data.
