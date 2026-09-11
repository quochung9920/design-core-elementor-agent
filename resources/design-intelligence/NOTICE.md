# Design Intelligence Attribution

Design Core's `resources/design-intelligence/catalog.json` is a normalized starter subset derived from concepts and public data in **UI UX Pro Max**:

- Upstream: https://github.com/nextlevelbuilder/ui-ux-pro-max-skill
- Copyright: 2024 Next Level Builder
- License: MIT

The upstream MIT license permits use, modification, merge, publication, distribution, sublicensing, and sale, provided the copyright and permission notice are retained in copies or substantial portions of the software.

Design Core does **not** ship UI UX Pro Max's Python runtime or its complete data repository. The bundled catalog is intentionally small so WordPress has no Python/runtime dependency. `tools/sync-uiux-promax.py` can generate a larger local normalized catalog from a separately obtained upstream checkout.

Any Google Fonts, Phosphor, or other third-party assets referenced by the upstream project retain their own upstream licenses. Design Core's starter catalog stores only design metadata/font family names and does not bundle upstream font files or icon binaries.
