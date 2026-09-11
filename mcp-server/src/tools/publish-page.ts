import { z } from 'zod';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { BridgeContext } from '../context.js';
import { siteField } from '../context.js';
import { resolveSite } from '../site-config.js';
import { callDesignCore } from '../design-core-client.js';
import { guarded, jsonResult } from '../tool-result.js';
import { resolveIdempotencyKey } from '../idempotency.js';
import { assertSiteWriteEnabled } from '../write-guard.js';

export function registerPublishPageTool(server: McpServer, ctx: BridgeContext): void {
  server.registerTool(
    'design_core_publish_page',
    {
      title: 'Design Core publish page',
      description: 'WRITE. Transitions a page to published status. Requires confirm:true. A no-op (reports already-published) if the page is already published.',
      inputSchema: {
        site: siteField(ctx.siteNames),
        page_id: z.number().int().positive(),
        confirm: z.literal(true).describe('Must be true. This tool makes a real page publicly visible.'),
        idempotency_key: z.string().min(1).max(200).optional(),
      },
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true, title: 'Publish page (write)' },
    },
    async (args) =>
      guarded(async () => {
        const site = resolveSite(ctx.sites, args.site);
        // P2: Enforce allowWrite at bridge level BEFORE network request. A denied write
        // throws WriteGuardError, which propagates to guarded()'s errorResult() conversion.
        assertSiteWriteEnabled(site, 'design_core_publish_page');
        const idempotencyKey = resolveIdempotencyKey('design_core_publish_page', args);
        const data = await callDesignCore(site, 'POST', `/pages/${args.page_id}/publish`, { confirm: true }, { idempotencyKey });
        return jsonResult(data);
      })
  );
}
