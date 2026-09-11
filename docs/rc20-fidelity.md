# rc20 Fidelity & Runtime Proof

Design Core Elementor rc20 deepens the rc19 architecture instead of adding another independent business-logic layer. The release focuses on four quality gaps: strategy-aware preflight, responsive layout reasoning, rendered visual evidence with governed correction, and direct-but-separated Figma transport.

## Safety boundaries

- Design IR v4 remains platform-neutral.
- Build Preview/Simulator never executes a strategy or mutates posts/registries.
- Visual correction is V3-only until a public V4 mutation contract exists.
- A correction requires a concrete Elementor element ID plus a live runtime control.
- Ambiguous, missing, private or non-responsive controls fail closed.
- Verified writes always use the Persistence Service and therefore save/reload/render/verify/history behavior.
- Remote screenshot/browser targets are URL-validated; rendered browser analysis constrains HTTP(S) subresources to the target origin plus explicit hosts.
- Figma authentication/network transport is separate from Figma → Design IR conversion.
- Figma credentials are externally configured; they are not persisted by the transport class.
- Agent/REST correction endpoints require administrator capability and explicit confirmation.

## BuildPlan Preview v2

`Design_Core_Elementor_Build_Plan_Simulator` predicts Elementor output from the actual chosen BuildPlan strategy.

Examples:

- `custom-widget`: a multi-node IR subtree can predict one Elementor widget;
- `native-widget`: predicts the selected runtime widget rather than one Elementor element per IR node;
- `reuse-component` / `variant`: uses a persisted blueprint when safely available;
- `native-compose`: falls back to IR-shape prediction with lower confidence.

The result includes predicted container/widget counts, widget frequencies, per-item confidence and reason. No executor or persistence path is called.

## Responsive Layout Intelligence v3

A node now owns a set of layout states. Desktop compatibility fields are retained at the top level, while `states` carries responsive pattern evidence.

Typical output:

```json
{
  "pattern": "split",
  "states": {
    "desktop": { "pattern": "split", "ratios": [0.4, 0.6] },
    "tablet": { "pattern": "split", "ratios": [0.5, 0.5] },
    "mobile": { "pattern": "stack", "columns": 1 }
  },
  "transitions": ["tablet->mobile:split->stack"],
  "responsive_pattern_change": true
}
```

Browser geometry is indexed for every captured viewport and the nearest viewport is selected for each responsive state.

## Rendered target evidence

`scripts/browser-analyze-target.mjs` is a bounded Playwright analyzer for an already rendered target. It captures:

- geometry;
- layout and spacing computed styles;
- text metrics;
- image/background fit and position;
- border radius;
- DOM/text identity;
- nearest Elementor `data-id`, `data-element_type` and `data-widget_type` ownership.

`Design_Core_Elementor_Browser_Analysis_Service::analyze_target()` validates the target before starting Node and bounds process time/output.

## Visual Feedback v3

Visual Feedback automatically requests rendered analysis when it has not already been supplied. It conservatively aligns reference/candidate nodes and produces element-addressable corrections.

A safe directive contains evidence similar to:

```json
{
  "viewport": 1440,
  "device": "desktop",
  "elementor_id": "a1b2c3d4",
  "widget_type": "heading",
  "category": "typography",
  "changes": [
    {
      "property": "font_size",
      "reference": "56px",
      "candidate": "48px"
    }
  ],
  "auto_applicable": true
}
```

A screenshot-only mismatch with no safe element/control mapping remains diagnostic and is not automatically applied.

## Visual Correction v2

`Design_Core_Elementor_Visual_Correction_Applier` loads the current V3 element tree and resolves each requested property against `Design_Core_Elementor_Control_Schema_Registry`.

Initial supported logical properties include:

- width/max-width/height/min-height;
- gap/row-gap/column-gap;
- padding/margin;
- justify-content/align-items;
- font size/line height/letter spacing/font weight/text alignment;
- object fit/object position;
- background size/background position;
- border radius.

CSS target values are converted to the discovered live control shape (`slider`, `dimensions`, `gaps`, scalar controls). Responsive writes are refused when the runtime control is not marked responsive.

`Design_Core_Elementor_Visual_Correction_Service` performs compare → apply → persist/verify → compare again for a bounded number of iterations. The rc19 external correction filter is still available as an integration fallback.

## Figma Transport + Adapter v2

`Design_Core_Elementor_Figma_Transport` parses normal Figma URLs, reads files/nodes, resolves image fills and can request node exports. `Design_Core_Elementor_Figma_Design_IR_Adapter` consumes the returned evidence and remains unaware of credentials/network behavior.

The adapter now retains sizing, constraints, component properties, bound variables, style evidence, text runs and prototype reactions. Resolved image URLs remain asset evidence; importing them into WordPress is a separate governed asset concern.

## Fidelity benchmark corpus

`Design_Core_Elementor_Design_Benchmark_Corpus` is the beginning of a repeatable quality scorecard. A benchmark has a fixed reference fixture, expected planning properties and a visual threshold. rc20 ships an initial set of five patterns and exposes them through admin, REST, CLI and the compact Agent Gateway.

The intended next stage is a larger corpus of real reference designs with per-viewport visual thresholds and expected Elementor architecture.

## Runtime evidence

rc20 adds `tests/visual-correction-smoke.php`. On a real Elementor V3 runtime it creates a heading, uses a Visual Feedback directive to request a font-size correction, applies the live control through the governed applier, reloads/renders the page and records `visual-correction-roundtrip` evidence.

`Production_Readiness` requires that evidence when V3 + browser-analysis capability is available. The test file or architecture check existing in the repository does not itself count as a passing release gate.

## CI caveat

The repository workflow includes PHP 8.1/8.2/8.3 lint/contracts, browser tooling, WordPress/Elementor runtime gates, responsive visual QA and optional Pro Loop coverage. A GitHub Actions `startup_failure` with zero jobs created is not a test pass or a code-test failure; release status must wait for actual executed job evidence.
