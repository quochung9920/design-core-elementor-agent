import { URLSearchParams } from 'node:url';
import { z } from 'zod';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { BridgeContext } from '../context.js';
import { siteField } from '../context.js';
import { resolveSite } from '../site-config.js';
import { callDesignCore } from '../design-core-client.js';
import { guarded, jsonResult } from '../tool-result.js';
import { resolveIdempotencyKey } from '../idempotency.js';
import { assertSiteWriteEnabled } from '../write-guard.js';

function queryPath(path: string, values: Record<string, string | number | boolean | undefined>): string {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(values)) {
    if (value === undefined || value === '') continue;
    params.set(key, String(value));
  }
  const query = params.toString();
  return query ? `${path}?${query}` : path;
}

export const SITE_INTELLIGENCE_TOOL_NAMES = [
  'design_core_site_map',
  'design_core_site_design_system',
  'design_core_search_content',
  'design_core_elementor_catalog',
  'design_core_widget_search',
  'design_core_widget_schema',
  'design_core_elementor_capabilities',
  'design_core_media_library',
  'design_core_plan_task',
  'design_core_create_page',
] as const;

export function registerSiteIntelligenceTools(server: McpServer, ctx: BridgeContext): void {
  server.registerTool(
    'design_core_site_map',
    {
      title: 'Design Core site map',
      description:
        'READ / SITE INTELLIGENCE. Returns a bounded structural map of the configured WordPress site: pages, public post types/counts, menus, Elementor template inventory, theme, homepage bindings and Design Core registry counts. Use this before asking the user for a page ID.',
      inputSchema: {
        site: siteField(ctx.siteNames),
        limit: z.number().int().min(1).max(200).optional().default(100),
      },
      annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: false, title: 'Site map' },
    },
    async (args) =>
      guarded(async () => {
        const site = resolveSite(ctx.sites, args.site);
        const data = await callDesignCore(site, 'GET', queryPath('/site/map', { limit: args.limit }));
        return jsonResult(data);
      })
  );

  server.registerTool(
    'design_core_site_design_system',
    {
      title: 'Design Core site design system',
      description:
        'READ / SITE INTELLIGENCE. Reads the actual Design Core tokens, Elementor active Kit global styles, runtime breakpoints and managed layout standard for this site. Use it before creating or modifying sections so new work follows the existing visual system.',
      inputSchema: { site: siteField(ctx.siteNames) },
      annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: false, title: 'Site design system' },
    },
    async (args) =>
      guarded(async () => {
        const site = resolveSite(ctx.sites, args.site);
        return jsonResult(await callDesignCore(site, 'GET', '/site/design-system'));
      })
  );

  server.registerTool(
    'design_core_search_content',
    {
      title: 'Design Core search site content',
      description:
        'READ / DISCOVERY. Searches real WordPress pages/posts/CPTs plus Design Core section/component registries and returns compact matches with Elementor/manifest flags. Use it to discover an existing page or reusable pattern from natural-language intent.',
      inputSchema: {
        site: siteField(ctx.siteNames),
        query: z.string().min(1).max(500),
        post_types: z.array(z.string().min(1).max(100)).max(20).optional(),
        limit: z.number().int().min(1).max(50).optional().default(20),
      },
      annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: false, title: 'Search site content' },
    },
    async (args) =>
      guarded(async () => {
        const site = resolveSite(ctx.sites, args.site);
        const data = await callDesignCore(
          site,
          'GET',
          queryPath('/site/search', {
            q: args.query,
            types: args.post_types?.join(','),
            limit: args.limit,
          })
        );
        return jsonResult(data);
      })
  );

  server.registerTool(
    'design_core_elementor_catalog',
    {
      title: 'Design Core Elementor catalog',
      description:
        'READ / ELEMENTOR INTELLIGENCE. Returns a bounded live inventory of registered Elementor Core, Elementor Pro, Design Core and third-party widgets, with semantic intents, runtime capability summaries, control counts and schema fingerprints. Elementor runtime is the source of truth.',
      inputSchema: {
        site: siteField(ctx.siteNames),
        source: z.enum(['all', 'core', 'pro', 'design_core', 'third_party']).optional().default('all'),
        query: z.string().max(500).optional().default(''),
        limit: z.number().int().min(1).max(200).optional().default(100),
      },
      annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: false, title: 'Elementor widget catalog' },
    },
    async (args) =>
      guarded(async () => {
        const site = resolveSite(ctx.sites, args.site);
        return jsonResult(
          await callDesignCore(
            site,
            'GET',
            queryPath('/elementor/catalog', { source: args.source, q: args.query, limit: args.limit })
          )
        );
      })
  );

  server.registerTool(
    'design_core_widget_search',
    {
      title: 'Design Core search Elementor widgets',
      description:
        'READ / ELEMENTOR INTELLIGENCE. Deterministically ranks widgets from the live Elementor runtime against intent, capability, control and keyword requirements. Use this instead of guessing a widget name from model memory.',
      inputSchema: {
        site: siteField(ctx.siteNames),
        query: z.string().max(1000).optional(),
        intent: z.enum(['general', 'commerce', 'form', 'navigation', 'dynamic-content', 'media', 'action', 'social', 'content', 'layout']).optional(),
        capabilities: z.array(z.string().min(1).max(100)).max(20).optional(),
        controls: z.array(z.string().min(1).max(150)).max(30).optional(),
        keywords: z.array(z.string().min(1).max(200)).max(30).optional(),
        limit: z.number().int().min(1).max(50).optional().default(10),
      },
      annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: false, title: 'Search Elementor widgets' },
    },
    async (args) =>
      guarded(async () => {
        const site = resolveSite(ctx.sites, args.site);
        const data = await callDesignCore(site, 'POST', '/elementor/widgets/search', {
          query: args.query,
          intent: args.intent,
          capabilities: args.capabilities,
          controls: args.controls,
          keywords: args.keywords,
          limit: args.limit,
        });
        return jsonResult(data);
      })
  );

  server.registerTool(
    'design_core_widget_schema',
    {
      title: 'Design Core Elementor widget schema',
      description:
        'READ / ELEMENTOR INTELLIGENCE. Inspects one registered widget against the live unified Control Schema Registry. Compact mode returns semantic/capability metadata plus a control sample; detail=true returns the complete flattened control contract and machine-readable JSON Schema.',
      inputSchema: {
        site: siteField(ctx.siteNames),
        widget: z.string().min(1).max(200),
        detail: z.boolean().optional().default(false),
      },
      annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: false, title: 'Widget control schema' },
    },
    async (args) =>
      guarded(async () => {
        const site = resolveSite(ctx.sites, args.site);
        return jsonResult(
          await callDesignCore(
            site,
            'GET',
            queryPath(`/elementor/widgets/${encodeURIComponent(args.widget)}`, { detail: args.detail })
          )
        );
      })
  );

  server.registerTool(
    'design_core_elementor_capabilities',
    {
      title: 'Design Core Elementor capabilities',
      description:
        'READ / ELEMENTOR INTELLIGENCE. Aggregates the current Elementor/Pro runtime: widget/control counts, semantic intent coverage, discovered capabilities, real Pro widget names, layout element schemas and actual Elementor template types. Does not assume a Pro feature exists merely because it existed in another version.',
      inputSchema: { site: siteField(ctx.siteNames) },
      annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: false, title: 'Elementor capabilities' },
    },
    async (args) =>
      guarded(async () => {
        const site = resolveSite(ctx.sites, args.site);
        return jsonResult(await callDesignCore(site, 'GET', '/elementor/capabilities'));
      })
  );

  server.registerTool(
    'design_core_media_library',
    {
      title: 'Design Core media library',
      description:
        'READ / WORDPRESS INTELLIGENCE. Searches the WordPress media library and returns bounded attachment metadata (URL, mime type, dimensions, alt text and caption). It never uploads, deletes or edits media.',
      inputSchema: {
        site: siteField(ctx.siteNames),
        query: z.string().max(500).optional().default(''),
        mime: z.string().max(100).optional().default(''),
        limit: z.number().int().min(1).max(50).optional().default(30),
      },
      annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: false, title: 'Media library' },
    },
    async (args) =>
      guarded(async () => {
        const site = resolveSite(ctx.sites, args.site);
        return jsonResult(
          await callDesignCore(site, 'GET', queryPath('/media', { q: args.query, mime: args.mime, limit: args.limit }))
        );
      })
  );

  server.registerTool(
    'design_core_plan_task',
    {
      title: 'Design Core plan task',
      description:
        'READ / PLAN. Converts a natural-language WordPress/Elementor task brief into a mutation-free plan grounded in the live site map, design system, page snapshot (when supplied), reusable content and runtime widget candidates. Returns a recommended safe tool sequence; it does not execute that sequence.',
      inputSchema: {
        site: siteField(ctx.siteNames),
        brief: z.string().min(1).max(16_384),
        page_id: z.number().int().positive().optional(),
      },
      annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: false, title: 'Plan Elementor task' },
    },
    async (args) =>
      guarded(async () => {
        const site = resolveSite(ctx.sites, args.site);
        return jsonResult(await callDesignCore(site, 'POST', '/tasks/plan', { brief: args.brief, page_id: args.page_id ?? 0 }));
      })
  );

  server.registerTool(
    'design_core_create_page',
    {
      title: 'Design Core create draft page',
      description:
        'WRITE / WORDPRESS. Creates one new draft WordPress page and marks it as Design Core MCP-created. It does not write Elementor storage. Requires confirm:true, the design_core_build scope, the site write kill-switch, matching credential environment and bridge allowWrite. Production creation is deliberately blocked until a separately reviewed production-safe creation flow exists. Elementor content is still changed only through preview-gated design_core_update_page.',
      inputSchema: {
        site: siteField(ctx.siteNames),
        title: z.string().min(1).max(200),
        slug: z.string().max(200).optional(),
        parent_id: z.number().int().positive().optional(),
        confirm: z.literal(true).describe('Must be true. This creates a real draft WordPress page.'),
        idempotency_key: z.string().min(1).max(200).optional(),
      },
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false, title: 'Create draft page' },
    },
    async (args) =>
      guarded(async () => {
        const site = resolveSite(ctx.sites, args.site);
        assertSiteWriteEnabled(site, 'design_core_create_page');
        const idempotencyKey = resolveIdempotencyKey('design_core_create_page', args);
        const data = await callDesignCore(
          site,
          'POST',
          '/pages/create',
          {
            title: args.title,
            slug: args.slug,
            parent_id: args.parent_id,
            confirm: true,
          },
          { idempotencyKey }
        );
        return jsonResult(data);
      })
  );
}
