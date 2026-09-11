import { z } from 'zod';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { BridgeContext } from '../context.js';
import { siteField } from '../context.js';
import { resolveSite } from '../site-config.js';
import { callDesignCore } from '../design-core-client.js';
import { guarded, jsonResult } from '../tool-result.js';

export function registerVisualFeedbackTool(server: McpServer, ctx: BridgeContext): void {
  server.registerTool(
    'design_core_visual_feedback',
    {
      title: 'Design Core visual feedback',
      description:
        'READ. Compares a reference screenshot/URL against a candidate (defaults to the live page render if candidate_target is omitted) and returns Visual Feedback directives with concrete Elementor element IDs when resolvable. Does not modify the page.',
      inputSchema: {
        site: siteField(ctx.siteNames),
        page_id: z.number().int().positive(),
        reference_target: z.string().min(1).describe('Reference screenshot path/URL or rendered page URL to compare against.'),
        candidate_target: z.string().optional().describe('Candidate to compare; defaults to the live page render when omitted.'),
        target_similarity: z.number().min(0).max(1).optional().default(0.95),
      },
      annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: true, title: 'Visual feedback (compare only)' },
    },
    async (args) =>
      guarded(async () => {
        const site = resolveSite(ctx.sites, args.site);
        const data = await callDesignCore(site, 'POST', `/pages/${args.page_id}/visual-feedback`, {
          reference_target: args.reference_target,
          candidate_target: args.candidate_target,
          target_similarity: args.target_similarity,
        });
        return jsonResult(data);
      })
  );
}
