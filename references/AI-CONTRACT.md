# AI Contract

Before building a page, the AI must:

1. Inspect the source HTML, CSS, JS and assets.
2. Read the existing Elementor capability report.
3. Search the Design Core component registry.
4. Detect repeated patterns and extract tokens.
5. Decide between native Elementor, component, loop, custom widget, CSS fallback, or HTML fallback.
6. Build only after a design IR and build plan are formed.
7. Register new reusable assets after implementation.
8. Validate the final output against the source design and responsive breakpoints.

The design must prioritize:

- native Elementor controls
- reuse-first component strategy
- responsive accuracy
- maintainability
- low custom CSS volume
