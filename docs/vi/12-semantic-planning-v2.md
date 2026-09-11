# Semantic Planning V2

Semantic Planning V2 adds an identity-first, runtime-schema-backed decision layer before Elementor compilation. It preserves component function, structure, behavior, responsive editing and maintainability instead of maximizing widget count.

The planner classifies each source component into a versioned contract, reads the live Elementor catalog and Control Schema Registry, applies hard rejection rules before scoring, ranks only compatible direct widgets, then falls back to a proven native composition or a purpose-built Design Core native widget. If no native solution can preserve the contract, it blocks instead of falling back to layout HTML.

Six additive MCP abilities are registered: component ontology, component plan, semantic preview, semantic audit, semantic apply-draft and semantic promote-draft. Existing Agent Protocol v1 abilities remain available for compatibility.

Semantic preview replays the same requirements against the current runtime and binds evidence to the exact preview/artifact. Semantic apply rejects missing or stale evidence. Promotion still requires the existing visual and interaction QA gate; semantic PASS never substitutes browser QA.

Initial purpose-built widgets: `dc-steps`, `dc-jump-nav`, `dc-responsive-table`, and `dc-enquiry-drawer`.

Quality invariants: exact token boundaries (`transform` never implies `form`); runtime controls are the source of truth; widget source/prestige does not increase fit; Button clusters are not semantic menus; structured repeated/table/process components cannot collapse into one Text Editor; unknown interaction behavior remains unverified.
