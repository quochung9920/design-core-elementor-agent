import { z } from 'zod';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { BridgeContext } from '../context.js';
import { siteField } from '../context.js';
import { resolveSite } from '../site-config.js';
import { callDesignCore } from '../design-core-client.js';
import { guarded, jsonResult } from '../tool-result.js';
import { resolveIdempotencyKey } from '../idempotency.js';
import { assertSiteWriteEnabled } from '../write-guard.js';

export function registerAutoCorrectTool(server: McpServer, ctx: BridgeContext): void {
  server.registerTool(
    'design_core_auto_correct',
    {
      title: 'Design Core auto-correct',
      description:
        'WRITE. Runs the governed compare -> correct -> persist -> re-verify loop against a real Elementor page, using only live, runtime-verified native Elementor controls resolved server-side -- this tool never sends a raw control path or arbitrary Elementor setting itself. Fails closed (returns needs-correction/max-iterations rather than guessing) when no safe control is available, the element mapping is ambiguous, the page changed since the reference was captured, or the current editor mode is not supported. Requires confirm:true.',
      inputSchema: {
        site: siteField(ctx.siteNames),
        page_id: z.number().int().positive(),
        reference_url: z.string().min(1).describe('Reference screenshot path/URL to correct the live page towards.'),
        candidate_target: z.string().optional().describe('Candidate to compare; defaults to the live page render when omitted.'),
        target_similarity: z.number().min(0).max(1).optional().default(0.97),
        max_iterations: z.number().int().min(1).max(5).optional().default(3),
        confirm: z.literal(true).describe('Must be true. This tool can mutate a real Elementor page.'),
        idempotency_key: z.string().min(1).max(200).optional(),
      },
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true, title: 'Auto-correct (write, governed)' },
    },
    async (args) =>
      guarded(async () => {
        const site = resolveSite(ctx.sites, args.site);
        // P2: Enforce allowWrite at bridge level BEFORE network request. A denied write
        // throws WriteGuardError, which propagates to guarded()'s errorResult() conversion.
        assertSiteWriteEnabled(site, 'design_core_auto_correct');
        const idempotencyKey = resolveIdempotencyKey('design_core_auto_correct', args);
        const data = await callDesignCore(
          site,
          'POST',
          `/pages/${args.page_id}/auto-correct`,
          {
            reference_target: args.reference_url,
            candidate_target: args.candidate_target,
            target_similarity: args.target_similarity,
            max_iterations: args.max_iterations,
            confirm: true,
          },
          { idempotencyKey }
        );
        return jsonResult(data);
      })
  );
}
