import { z } from 'zod';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { BridgeContext } from '../context.js';
import { siteField } from '../context.js';
import { resolveSite } from '../site-config.js';
import { callDesignCore } from '../design-core-client.js';
import { guarded, jsonResult } from '../tool-result.js';

interface RecommendationResponse {
  status?: unknown;
  profile?: {
    product?: unknown;
    direction?: unknown;
    page_strategy?: unknown;
    tokens?: unknown;
    ux?: unknown;
    provenance?: unknown;
  };
  catalog?: unknown;
  warnings?: unknown;
  mutation?: unknown;
  [key: string]: unknown;
}

function compact(data: RecommendationResponse) {
  return {
    status: data.status,
    product: data.profile?.product,
    direction: data.profile?.direction,
    page_strategy: data.profile?.page_strategy,
    tokens: data.profile?.tokens,
    anti_patterns: (data.profile?.ux as { anti_patterns?: unknown } | undefined)?.anti_patterns,
    warnings: data.warnings,
    mutation: data.mutation,
    note: 'Read-only Design Intelligence recommendation. Pass detail:true to include the full UX rule/provenance payload.',
  };
}

export function registerRecommendDesignSystemTool(server: McpServer, ctx: BridgeContext): void {
  server.registerTool(
    'design_core_recommend_design_system',
    {
      title: 'Design Core recommend design system',
      description:
        'READ / DESIGN INTELLIGENCE. Recommends a platform-neutral Design System Profile from a product brief using Design Core\'s local normalized UI/UX knowledge catalog. Returns style direction, semantic color/typography/spacing tokens, page strategy, UX rules and anti-patterns. Never mutates WordPress and never runs Python or calls the upstream catalog at runtime.',
      inputSchema: {
        site: siteField(ctx.siteNames),
        brief: z.string().min(1).max(16_384),
        product_type: z.string().max(200).optional().describe('Optional explicit category override, e.g. B2B Service.'),
        mode: z.enum(['light', 'dark']).optional().default('light'),
        variance: z.number().int().min(1).max(10).optional(),
        motion: z.number().int().min(1).max(10).optional(),
        density: z.number().int().min(1).max(10).optional(),
        detail: z.boolean().optional().default(false),
      },
      annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: false, title: 'Recommend design system (read-only)' },
    },
    async (args) =>
      guarded(async () => {
        const site = resolveSite(ctx.sites, args.site);
        const data = (await callDesignCore(site, 'POST', '/design-intelligence/recommend', {
          brief: args.brief,
          product_type: args.product_type,
          mode: args.mode,
          variance: args.variance,
          motion: args.motion,
          density: args.density,
        })) as RecommendationResponse;
        return jsonResult(args.detail ? data : compact(data));
      })
  );
}
