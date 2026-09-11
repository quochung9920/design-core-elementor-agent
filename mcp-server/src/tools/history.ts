import { z } from 'zod';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { BridgeContext } from '../context.js';
import { siteField } from '../context.js';
import { resolveSite } from '../site-config.js';
import { callDesignCore } from '../design-core-client.js';
import { guarded, jsonResult } from '../tool-result.js';

interface HistoryEntry {
  id?: unknown;
  timestamp?: unknown;
  action?: unknown;
  object_id?: unknown;
  rollback_available?: unknown;
  rolled_back_at?: unknown;
  [key: string]: unknown;
}

export function registerHistoryTool(server: McpServer, ctx: BridgeContext): void {
  server.registerTool(
    'design_core_history',
    {
      title: 'Design Core history',
      description:
        'READ. Lists persistent Design Core change ledger entries (metadata only -- no raw before/after payloads), including whether each entry can still be rolled back.',
      inputSchema: {
        site: siteField(ctx.siteNames),
        page_id: z.number().int().positive().optional().describe('Filter to entries for one page; omit for all recent entries.'),
        limit: z.number().int().min(1).max(200).optional().default(50),
      },
      annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: true, title: 'History' },
    },
    async (args) =>
      guarded(async () => {
        const site = resolveSite(ctx.sites, args.site);
        const data = (await callDesignCore(site, 'GET', '/history')) as HistoryEntry[];
        const filtered = args.page_id ? data.filter((e) => Number(e.object_id) === args.page_id) : data;
        return jsonResult(filtered.slice(0, args.limit));
      })
  );
}
