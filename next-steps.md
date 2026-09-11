# Design Core Elementor — next steps after RC23

RC23 establishes the strict Figma fidelity path: authored Figma layout semantics, mixed text composition, vector/media preservation, draft-only Elementor compilation and rendered Figma reference verification.

## Immediate acceptance work

1. Run the PawCare node `4:1049` through a real WordPress + Elementor + Playwright runtime with a configured Figma access token.
2. Keep the draft blocked until the Figma-rendered reference reaches the configured visual similarity target and architecture/interaction gates are satisfied.
3. Capture the real PawCare result as a durable regression fixture once the target environment is available.
4. Expand Figma responsive handling with explicit source frames/variants when the design file includes tablet/mobile nodes rather than inventing breakpoints from the desktop frame.
5. Add a governed interaction wrapper for icon-bearing compound buttons so visual composition and a single click target are both preserved without flattening vector descendants.
6. Add first-class local/custom font ingestion. RC23 enqueues Google-hosted families used by inline runs but does not fabricate unavailable proprietary fonts.

## Broader roadmap

- Increase the rendered reference corpus to 50–100 representative real layouts.
- Add structural correction for high-confidence section hierarchy mismatches rather than relying on CSS repair.
- Improve cross-markup matching using stable source/Figma ownership markers.
- Keep documentation tied to booted runtime capabilities and CI contracts.
- Require successful live Elementor round-trip evidence before calling a release production-ready.
