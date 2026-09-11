# Design Core API-first platform

Design Core is now explicitly treated as an API-first design/build engine. The MCP bridge is a transport adapter, not the place where Elementor business logic lives.

```text
ChatGPT / Codex / Claude / Web UI
              |
              v
        Design Core MCP
      (thin transport adapter)
              |
              v
Design Core REST API v2 (stable contract)
              |
              v
Site Intelligence + Design Intelligence + BuildPlan + Safety
              |
              v
        Elementor adapters
              |
              v
     WordPress + Elementor
```

## Base URL and authentication

The stable site-local API lives under:

```text
/wp-json/design-core-elementor/v2
```

Remote clients authenticate with the existing scoped Design Core machine credential:

```http
Authorization: Bearer <dcmcp credential>
```

The same scope model remains authoritative: `design_core_read`, `design_core_preview`, `design_core_build`, `design_core_modify`, `design_core_publish`, and `design_core_rollback`.

No generic "call arbitrary PHP" endpoint exists. The API exposes bounded capabilities only.

## Machine-readable discovery

Two read-only endpoints make the external contract discoverable:

```text
GET /api
GET /api/openapi
```

`GET /api` returns the API principles, permission/mutation metadata, and a one-to-one mapping for all 22 MCP tools. `GET /api/openapi` returns an OpenAPI 3.1 discovery document with security and Design Core safety extensions.

## High-level read-only orchestration

For AI clients that need enough context to plan without manually issuing several primitive requests:

```text
POST /api/understand
```

Example body:

```json
{
  "brief": "Create a BIM engineering landing page using the current site design language.",
  "page_id": 0,
  "site_map_limit": 100
}
```

The response composes, without mutation:

- site status;
- site map;
- live Design Core/Elementor design system;
- live Elementor/Elementor Pro capabilities;
- a Design Core task plan.

After a build, a client can request state/quality verification:

```text
POST /api/pages/{id}/verify
```

This returns page state, the Design Core snapshot, UX audit, and page history. It does **not** pretend to perform visual equivalence; reference-based visual comparison remains the dedicated `/pages/{id}/visual-feedback` operation.

## Complete MCP-to-API mapping

| MCP tool | HTTP | REST path |
| --- | --- | --- |
| `design_core_site_status` | GET | `/site/status` |
| `design_core_page_snapshot` | GET | `/pages/{id}/snapshot` |
| `design_core_preview_build` | POST | `/build/preview` |
| `design_core_preview_figma` | POST | `/figma/preview` |
| `design_core_recommend_design_system` | POST | `/design-intelligence/recommend` |
| `design_core_preview_design_system` | POST | `/design-intelligence/preview` |
| `design_core_update_page` | POST | `/pages/{id}/update` |
| `design_core_visual_feedback` | POST | `/pages/{id}/visual-feedback` |
| `design_core_auto_correct` | POST | `/pages/{id}/auto-correct` |
| `design_core_history` | GET | `/history` |
| `design_core_rollback` | POST | `/history/{entry}/rollback` |
| `design_core_publish_page` | POST | `/pages/{id}/publish` |
| `design_core_site_map` | GET | `/site/map` |
| `design_core_site_design_system` | GET | `/site/design-system` |
| `design_core_search_content` | GET | `/site/search` |
| `design_core_elementor_catalog` | GET | `/elementor/catalog` |
| `design_core_widget_search` | POST | `/elementor/widgets/search` |
| `design_core_widget_schema` | GET | `/elementor/widgets/{widget}` |
| `design_core_elementor_capabilities` | GET | `/elementor/capabilities` |
| `design_core_media_library` | GET | `/media` |
| `design_core_plan_task` | POST | `/tasks/plan` |
| `design_core_create_page` | POST | `/pages/create` |

This means the current MCP bridge is already API-backed end-to-end. The new platform contract makes that architecture explicit and consumable by other clients without duplicating Design Core logic.

## Safety invariants

API-first does not mean broader write access. Existing guarantees remain in force:

- no direct mutation of `_elementor_data` from an AI transport;
- existing page updates require an approved `preview_id` + `plan_hash` + `confirm=true`;
- draft creation remains confirmation-gated and does not write Elementor storage;
- write kill-switch and environment matching remain server-side;
- production guards remain server-side;
- idempotency remains enforced for remote mutations;
- concurrent/stale page state fails closed instead of overwriting;
- history/rollback stays inside the Design Core transaction model.

## Recommended ChatGPT production shape

Keep detailed REST operations for SDK/automation use. ChatGPT should consume a smaller high-level MCP surface over the same API, for example:

```text
understand -> plan -> create draft -> preview -> apply -> verify -> rollback/publish
```

Do not expose WordPress, SQL, WP-CLI, or Elementor storage as alternate mutation paths to the model.

## Contract test

Run:

```bash
php tests/api-platform/run.php
```

The contract test verifies the one-to-one 22-tool API mapping, mutation metadata, API-first invariants, and OpenAPI discovery surface.
