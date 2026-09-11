# RC25 Fidelity Hardening

RC25 tightens the Figma -> Elementor path around the failures that are easiest to miss in an IR-only test: composite icons becoming containers, transformed image crops being guessed, correct-looking elements living under the wrong parent, fallback fonts masquerading as authored typography, stale source-scoped Design Memory, and screenshot scores hiding regional mismatches.

## Compiler changes

- `Figma_Vector_Asset_Resolver` discovers small vector-only INSTANCE/COMPONENT/FRAME/GROUP composites and painted primitives by geometry/type/paint evidence. Successfully exported composites are collapsed to one exact SVG media leaf before Design IR lowering.
- `Figma_Raster_Asset_Resolver` detects simple leaf IMAGE paints whose visual result depends on Figma `STRETCH`/`imageTransform`, filters or rotation. These atoms are rendered by Figma as PNG and rebound to the original source node, avoiding guessed CSS crop/focal values.
- Transformed raster export is deliberately conservative: child-bearing, multi-paint, stroked or effected nodes remain structural so Design Core does not double-render decoration.
- Figma transport source identity now includes the Figma version and a selected-node structural hash. Source-scoped memory therefore changes when the selected source revision changes.
- strict prepare fails closed when required vector/icon or transformed-raster candidates are discovered but cannot be exported; silent asset loss or crop guessing is not accepted.

## Render verification

The browser analyzer schema now records:

- exact Figma class and nearest Figma parent class;
- owning Elementor ID and parent Elementor owner ID;
- primary computed font family;
- FontFaceSet declaration/check evidence plus a bounded glyph-metric fallback proof;
- geometry and computed styles as before.

`Figma_Geometry_Verifier` remains the exact x/y/width/height verifier. RC25 adds:

- `Figma_Structure_Verifier` for exact Figma parent ownership;
- `Figma_Font_Verifier` for authored font-family proof;
- `Figma_Responsive_Verifier` for responsive runtime safety when no independent mobile/tablet Figma frames exist.

Responsive runtime verification is deliberately labelled `inferred-runtime`. It proves that the compiled Figma root adapts without gross horizontal overflow at governed smaller viewports; it does **not** claim mobile pixel fidelity without an independent mobile reference.

## Visual Diff v4

`visual-compare.mjs` now returns a composite visual similarity based on:

- strict pixel similarity;
- bounded luminance/perceptual similarity;
- dimension agreement.

It also emits a 4x4 regional score grid and the worst mismatching regions. Exact Figma geometry remains a separate hard gate so perceptual tolerance cannot hide structural displacement.

## Structural Diff v2

Generic rendered-reference comparison still checks element count, section order, document height and layout-pattern counts. When both sides expose `dc-figma-node-*` evidence, it additionally checks exact Figma parent ownership and reports the owning Elementor ID for rebuild decisions.

## Design Memory v2

Design Memory remains bounded and accepts only verified lessons. RC25 adds:

- revision-aware source fingerprints;
- min/max Design Core version compatibility;
- Fidelity Rule Registry version compatibility;
- explicit structure/font failure signatures;
- evidence fields for perceptual, geometry, structure and font verification.

A quality-gate failure is passed to the learning engine as a failed observation. A desktop screenshot PASS is therefore not enough to teach a successful lesson if required responsive/runtime evidence still fails.

## Completion gate

A strict Figma build is not complete merely because Elementor storage is valid. The final state must have:

1. complete required vector exports;
2. complete required transformed-raster exports;
3. exact persisted Elementor tree reload;
4. Figma reference screenshot above threshold;
5. exact Figma-node geometry evidence;
6. exact Figma parent-ownership evidence;
7. authored font proof where typography exists;
8. responsive runtime safety or stronger responsive reference evidence;
9. architecture quality gate PASS.

Run the authoritative local validation before merge:

```bash
./scripts/ci-local.sh
```

GitHub Actions are advisory while the repository runner-startup issue remains unresolved.
