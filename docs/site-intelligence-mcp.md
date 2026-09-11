# Site Intelligence v1 MCP

Site Intelligence v1 turns the Design Core bridge from a page-ID-first executor into a runtime-grounded discovery layer for WordPress + Elementor/Elementor Pro.

## Contract

The live WordPress/Elementor runtime remains the source of truth. The bridge does not hard-code a universal Elementor Pro widget/control catalog and does not accept arbitrary target URLs from the model. Every call is still bound to a preconfigured `DESIGN_CORE_SITE_<NAME>_*` site.

The release adds ten tools to the existing twelve-tool bridge, for **22 MCP tools total**:

### Read / discovery / planning

- `design_core_site_map` — pages, public post types/counts, menus, Elementor templates, theme/homepage bindings and Design Core registry counts.
- `design_core_site_design_system` — Design Core tokens, active Elementor Kit global styles, runtime breakpoints and the managed layout standard.
- `design_core_search_content` — bounded WordPress content search plus section/component registry matches.
- `design_core_elementor_catalog` — live runtime widget inventory across Elementor Core, Pro, Design Core and third parties.
- `design_core_widget_search` — deterministic candidate ranking by intent, capabilities, controls and keywords.
- `design_core_widget_schema` — compact or full live Control Schema Registry contract for one widget.
- `design_core_elementor_capabilities` — aggregate runtime coverage, Pro widget names, layout schemas and actual Elementor template types.
- `design_core_media_library` — bounded read-only WordPress media discovery.
- `design_core_plan_task` — mutation-free task planning grounded in the site map, design system, runtime widgets and optional target-page snapshot.

### Write

- `design_core_create_page` — creates a **draft only**, marks it `_design_core_mcp_created=1`, writes no Elementor storage, and requires `confirm:true`, `design_core_build`, the global write switch, matching environment, bridge `allowWrite` and idempotency. Production creation is deliberately refused until a separately reviewed production flow exists.

Existing page mutations remain unchanged: page content still requires `design_core_preview_build` / `design_core_preview_figma` / `design_core_preview_design_system` followed by the exact `preview_id + plan_hash + confirm:true` in `design_core_update_page`.

## Progressive discovery

Do not request every widget control up front. A preferred agent flow is:

1. `design_core_site_map`
2. `design_core_site_design_system`
3. `design_core_search_content` when locating an existing page/pattern
4. `design_core_widget_search` from semantic intent
5. `design_core_widget_schema` only for shortlisted widgets
6. `design_core_plan_task`
7. create a draft page if needed
8. preview
9. explicit approval/write
10. visual feedback / correction
11. publish only after explicit confirmation

This keeps model context bounded while still allowing the model to discover the exact active Elementor/Pro runtime instead of relying on stale model knowledge.

## REST v2 endpoints

The MCP tools are thin pass-throughs to these Design Core endpoints:

- `GET /site/map`
- `GET /site/design-system`
- `GET /site/search`
- `GET /elementor/catalog`
- `POST /elementor/widgets/search`
- `GET /elementor/widgets/<widget>`
- `GET /elementor/capabilities`
- `GET /media`
- `POST /tasks/plan`
- `POST /pages/create`

All are under `/wp-json/design-core-elementor/v2`. Read endpoints require `design_core_read`. Page creation requires `design_core_build` and all remote-write guards.

## Safety invariants

- No arbitrary site URL from the model.
- Elementor runtime is the widget/control authority.
- Unknown controls are not invented.
- Read tools never consult or change the global write switch.
- `design_core_create_page` is draft-only and production-blocked.
- Creation and existing-page writes are idempotency protected.
- Existing-page writes still require preview tickets and exact plan hashes.
- Public MCP authentication/rate limiting and Caddy exposure rules are unchanged.
