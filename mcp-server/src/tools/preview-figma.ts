import { z } from 'zod';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { BridgeContext } from '../context.js';
import { siteField } from '../context.js';
import { resolveSite } from '../site-config.js';
import { callDesignCore } from '../design-core-client.js';
import { guarded, jsonResult } from '../tool-result.js';

export function registerPreviewFigmaTool(server: McpServer, ctx: BridgeContext): void {
  server.registerTool(
    'design_core_preview_figma',
    {
      title: 'Design Core Figma preview',
      description:
        "READ / PREVIEW. Reads a Figma URL through Design Core's own Figma Transport + Design IR Adapter and previews the resulting BuildPlan with zero mutation. Returns a preview_id + plan_hash for design_core_update_page. `instructions` is recorded for traceability only -- Design Core does not run it through any interpreter, so it never changes planning. If the site has no Figma token configured, this returns a clear configuration error rather than a generic failure.",
      inputSchema: {
        site: siteField(ctx.siteNames),
        page_id: z.number().int().nonnegative().optional(),
        figma_url: z.string().url().describe('A figma.com design/file/proto/board URL.'),
        node_id: z.string().optional().describe('Specific Figma node ID to convert; omit to convert the whole file document.'),
        instructions: z.string().max(2000).optional().describe('Free-text guidance (e.g. "keep header/footer"), echoed back for traceability only.'),
        adapter_target: z.enum(['auto', 'elementor-v3', 'elementor-v4']).optional().default('auto'),
        detail: z.boolean().optional().default(false),
      },
      annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: true, title: 'Preview Figma import (dry run)' },
    },
    async (args) =>
      guarded(async () => {
        const site = resolveSite(ctx.sites, args.site);
        const data = (await callDesignCore(site, 'POST', '/figma/preview', {
          page_id: args.page_id ?? 0,
          figma_url: args.figma_url,
          node_id: args.node_id,
          instructions: args.instructions,
          adapter_target: args.adapter_target,
        })) as Record<string, unknown>;
        if (args.detail) return jsonResult(data);
        const { items: _items, execution_simulation: _sim, ...compact } = data as Record<string, unknown>;
        return jsonResult({ ...compact, note: 'Compact view. Pass detail:true for the full per-item plan.' });
      })
  );
}
