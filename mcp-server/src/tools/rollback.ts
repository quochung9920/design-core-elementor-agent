import { z } from 'zod';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { BridgeContext } from '../context.js';
import { siteField } from '../context.js';
import { resolveSite } from '../site-config.js';
import { callDesignCore } from '../design-core-client.js';
import { guarded, jsonResult } from '../tool-result.js';
import { resolveIdempotencyKey } from '../idempotency.js';
import { assertSiteWriteEnabled } from '../write-guard.js';

export function registerRollbackTool(server: McpServer, ctx: BridgeContext): void {
  server.registerTool(
    'design_core_rollback',
    {
      title: 'Design Core rollback',
      description:
        'DESTRUCTIVE. Conflict-aware rollback of a completed Elementor save. Refused if the page changed since that history entry, if it was already rolled back, or if its snapshot could not be durably captured (rollback_available:false from design_core_history/page_snapshot). Requires confirm:true.',
      inputSchema: {
        site: siteField(ctx.siteNames),
        entry_id: z.string().min(1),
        confirm: z.literal(true).describe('Must be true. This tool reverts a real Elementor page to a prior state.'),
        idempotency_key: z.string().min(1).max(200).optional(),
      },
      annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: true, title: 'Rollback (destructive)' },
    },
    async (args) =>
      guarded(async () => {
        const site = resolveSite(ctx.sites, args.site);
        // P2: Enforce allowWrite at bridge level BEFORE network request. A denied write
        // throws WriteGuardError, which propagates to guarded()'s errorResult() conversion.
        assertSiteWriteEnabled(site, 'design_core_rollback');
        const idempotencyKey = resolveIdempotencyKey('design_core_rollback', args);
        const data = await callDesignCore(site, 'POST', `/history/${encodeURIComponent(args.entry_id)}/rollback`, { confirm: true }, { idempotencyKey });
        return jsonResult(data);
      })
  );
}
