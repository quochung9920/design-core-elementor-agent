import { z } from 'zod';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { BridgeContext } from '../context.js';
import { siteField } from '../context.js';
import { resolveSite } from '../site-config.js';
import { callDesignCore } from '../design-core-client.js';
import { guarded, jsonResult } from '../tool-result.js';
import { resolveIdempotencyKey } from '../idempotency.js';
import { assertSiteWriteEnabled } from '../write-guard.js';

export function registerUpdatePageTool(server: McpServer, ctx: BridgeContext): void {
  server.registerTool(
    'design_core_update_page',
    {
      title: 'Design Core update page',
      description:
        'WRITE. Executes an already-approved preview (from design_core_preview_build or design_core_preview_figma) against a real Elementor page. Requires the exact preview_id + plan_hash from that preview and confirm:true -- there is no way to skip preview/approval. Refused with a conflict if the page changed since the preview, or if plan_hash does not match. Retrying the identical call is safe (idempotent) whether or not you supply idempotency_key.',
      inputSchema: {
        site: siteField(ctx.siteNames),
        page_id: z.number().int().positive(),
        preview_id: z.string().min(1),
        plan_hash: z.string().min(1),
        confirm: z.literal(true).describe('Must be true. This tool mutates a real Elementor page.'),
        idempotency_key: z.string().min(1).max(200).optional().describe('Optional stable key to make a specific retry explicitly safe; auto-derived from the call if omitted.'),
      },
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true, title: 'Update page (write, approval-gated)' },
    },
    async (args) =>
      guarded(async () => {
        const site = resolveSite(ctx.sites, args.site);
        // P2: Enforce allowWrite at bridge level BEFORE network request. A denied write
        // throws WriteGuardError, which propagates to guarded()'s errorResult() conversion.
        assertSiteWriteEnabled(site, 'design_core_update_page');
        const idempotencyKey = resolveIdempotencyKey('design_core_update_page', args);
        const data = await callDesignCore(
          site,
          'POST',
          `/pages/${args.page_id}/update`,
          { preview_id: args.preview_id, plan_hash: args.plan_hash, confirm: true },
          { idempotencyKey }
        );
        return jsonResult(data);
      })
  );
}
