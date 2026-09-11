# Reference integration provenance

Design Core reviewed the following public projects on 2026-09-05 while designing the corresponding subsystems.

| Project | Reviewed commit | License | Inclusion mode |
|---|---|---|---|
| https://github.com/style-dictionary/style-dictionary | `863685dce3ef178f15040d45be02d2a829ee49a8` | Apache-2.0 | concepts/architecture only; no source bundled |
| https://github.com/MyIntervals/PHP-CSS-Parser | `7df1210647fde459b0621c05a0bbbf7054aa0c14` | MIT | Composer dependency as `sabberworm/php-css-parser`; upstream license applies to that package |
| https://github.com/WordPress/mcp-adapter | `653e0cbe77fdbff62866d6d445f0a09e6ffc4cb3` | GPL-2.0-or-later | optional interoperability target; no source bundled |
| https://github.com/msrbuilds/elementor-mcp | `29e3fdddcbce288ba91cde09d233faa94adcd947` | GPL-2.0 | behavioral/runtime lessons only; no source bundled |
| https://github.com/bernaferrari/FigmaToCode | `f5c4831d5de6cffc19a73fe2823c56b4bb551281` | GPL-3.0 | compiler/normalization concepts only; no source bundled |

The Design Core implementations for token compilation, CSS rule normalization, WordPress MCP compatibility, Elementor setting governance and Figma normalization are original project code. No GPL source from the reference projects is copied into those implementations.

When Composer dependencies are distributed with a release, retain the license metadata/files generated for those dependencies in `vendor/` or in the release's third-party notices.
