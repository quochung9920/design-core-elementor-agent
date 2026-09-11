# RC23 — Figma Fidelity Engine v4

RC23 makes a node-specific Figma URL an authoritative design source for the local Hermes/OpenCode workflow. It does not ask an AI model to reconstruct the layout from a screenshot or to guess an Elementor tree.

## Pipeline

`Figma node -> REST source -> normalization v2 -> text composition -> Design IR v4 -> live Elementor control mapping -> draft-only persistence -> Figma PNG vs rendered root comparison`

The strict Figma compiler preserves:

- Figma Auto Layout direction, gap and axis alignment.
- `FILL`, `HUG` and `FIXED` sizing evidence, including flex-fill behavior.
- real four-sided padding instead of disconnected properties that Elementor never received.
- relative absolute positioning and authored bottom/right anchors.
- clipped media frames, exact fixed media heights and background/image fills.
- exported vector leaf assets instead of redrawn icons.
- mixed text style runs and multi-node headings such as `pet calm and you` where one word uses another font/style.
- a stable `dc-figma-node-*` class on rendered roots for selector-scoped screenshot verification.

Unsupported material mappings fail explicitly. They are not silently dropped. Governed element-scoped CSS remains the last fallback where a native live control cannot represent authored Figma behavior.

## Rendered reference

`Design_Core_Elementor_Figma_Transport` can export the selected node as a PNG directly from Figma. The screenshot service can compare that PNG with only the matching Elementor root instead of comparing an entire WordPress page against a section image.

The visual comparator now penalizes image-dimension drift rather than returning `not comparable`, which catches collapsed columns and wrong section heights. Browser capture waits for web fonts, disables animations and may allow explicitly named static font/image/style hosts while keeping third-party script requests blocked.

## Local agent commands

Configure a Figma personal access token as `DESIGN_CORE_FIGMA_ACCESS_TOKEN`, then:

```bash
wp design-core-agent status
wp design-core-agent figma-prepare --url='https://www.figma.com/design/FILE/NAME?node-id=4-1049'
wp design-core-agent figma-compile --url='https://www.figma.com/design/FILE/NAME?node-id=4-1049'
wp design-core-agent figma-build --url='https://www.figma.com/design/FILE/NAME?node-id=4-1049' --title='PawCare Hero' --confirm=1
wp design-core-agent figma-verify --url='https://www.figma.com/design/FILE/NAME?node-id=4-1049' --page-id=123 --candidate='http://local.test/page/' --target=.95
```

`figma-build` writes only to a draft page. It never publishes. A visual result below the requested similarity is `needs-correction`, never success.

## PawCare acceptance target

The regression target that motivated RC23 is PawCare node `4:1049`. The acceptance checks cover the failures observed in the earlier generated page:

- two-column hero does not collapse;
- 62px mixed Manrope/Newsreader heading stays on the intended four visual lines;
- `calm` remains inline and italic;
- right media frame retains its authored height;
- `NEXT AVAILABLE` retains its negative bottom overlay anchor;
- the emergency indicator remains a compact dot instead of stretching vertically;
- Figma padding and axis alignment survive Elementor lowering.

The repository includes deterministic synthetic tests for these semantics. A live Figma/WordPress/Elementor acceptance run still requires a configured Figma token, Elementor runtime and Playwright browser on the machine that runs the plugin.
