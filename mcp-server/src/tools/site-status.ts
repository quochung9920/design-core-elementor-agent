import { z } from 'zod';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { BridgeContext } from '../context.js';
import { siteField } from '../context.js';
import { resolveSite } from '../site-config.js';
import { callDesignCore } from '../design-core-client.js';
import { guarded, jsonResult } from '../tool-result.js';

export function registerSiteStatusTool(server: McpServer, ctx: BridgeContext): void {
  server.registerTool(
    'design_core_site_status',
    {
      title: 'Design Core site status',
      description:
        'READ. Compact health/capability summary for a Design Core site: plugin/WordPress/Elementor versions, editor mode, Figma configuration, and whether remote writes are currently enabled. Never returns secrets.',
      inputSchema: { site: siteField(ctx.siteNames) },
      annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: true, title: 'Site status' },
    },
    async (args) =>
      guarded(async () => {
        const site = resolveSite(ctx.sites, args.site);
        const data = await callDesignCore(site, 'GET', '/site/status');
        return jsonResult(data);
      })
  );
}
