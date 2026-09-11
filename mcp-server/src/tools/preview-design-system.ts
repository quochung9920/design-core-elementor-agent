import { z } from 'zod';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { BridgeContext } from '../context.js';
import { siteField } from '../context.js';
import { resolveSite } from '../site-config.js';
import { callDesignCore } from '../design-core-client.js';
import { guarded, jsonResult } from '../tool-result.js';

interface DesignPreviewResponse {
  recommendation?: unknown;
  preview_id?: unknown;
  plan_hash?: unknown;
  page_id?: unknown;
  current_page_hash?: unknown;
  expires_at?: unknown;
  adapter_target?: unknown;
  can_execute_safely?: unknown;
  unsafe_reasons?: unknown;
  estimated_element_count?: unknown;
  strategy_counts?: unknown;
  requirements?: unknown;
  warnings?: unknown;
  items?: unknown;
  execution_simulation?: unknown;
  placeholder_bindings?: unknown;
  exploratory_only?: unknown;
  mutation?: unknown;
  [key: string]: unknown;
}

function compact(data: DesignPreviewResponse) {
  const recommendation = data.recommendation as { profile?: { product?: unknown; direction?: unknown; page_strategy?: unknown } } | undefined;
  return {
    product: recommendation?.profile?.product,
    direction: recommendation?.profile?.direction,
    page_strategy: recommendation?.profile?.page_strategy,
    preview_id: data.preview_id,
    plan_hash: data.plan_hash,
    page_id: data.page_id,
    current_page_hash: data.current_page_hash,
    expires_at: data.expires_at,
    adapter_target: data.adapter_target,
    can_execute_safely: data.can_execute_safely,
    unsafe_reasons: data.unsafe_reasons,
    estimated_element_count: data.estimated_element_count,
    strategy_counts: data.strategy_counts,
    requirements: data.requirements,
    warnings: data.warnings,
    placeholder_bindings: data.placeholder_bindings,
    exploratory_only: data.exploratory_only,
    mutation: data.mutation,
    note:
      data.exploratory_only === true
        ? 'Exploratory-only preview. It may use placeholder content and cannot execute against a real page. Create a new page-bound preview with explicit bindings before approval.'
        : 'Mutation-free page-bound design preview. If acceptable, use preview_id + plan_hash with design_core_update_page; approval rules remain unchanged.',
  };
}

export function registerPreviewDesignSystemTool(server: McpServer, ctx: BridgeContext): void {
  server.registerTool(
    'design_core_preview_design_system',
    {
      title: 'Design Core preview recommended design',
      description:
        'READ / PREVIEW. Recommends a local Design System Profile, compiles its existing Design Core Page Shell into Design IR, enriches semantic tokens, and runs the normal BuildPlan preview. Zero page mutation. Exploratory page_id=0 previews may use deterministic placeholder bindings and cannot execute against a real page. A page-bound preview requires explicit content bindings; only that preview_id + plan_hash can later be passed to design_core_update_page after explicit approval.',
      inputSchema: {
        site: siteField(ctx.siteNames),
        brief: z.string().min(1).max(16_384),
        page_id: z.number().int().nonnegative().optional().describe('Existing page to bind the approval ticket to. Omit or use 0 for an exploratory, non-executable preview.'),
        product_type: z.string().max(200).optional(),
        mode: z.enum(['light', 'dark']).optional().default('light'),
        variance: z.number().int().min(1).max(10).optional(),
        motion: z.number().int().min(1).max(10).optional(),
        density: z.number().int().min(1).max(10).optional(),
        bindings: z
          .record(z.string(), z.unknown())
          .optional()
          .describe('Page Shell section bindings. Optional only for exploratory page_id=0; required by Design Core for any real page-bound preview so placeholder copy can never be approved accidentally.'),
        adapter_target: z.enum(['auto', 'elementor-v3', 'elementor-v4']).optional().default('auto'),
        detail: z.boolean().optional().default(false),
      },
      annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: false, title: 'Preview recommended design (dry run)' },
    },
    async (args) =>
      guarded(async () => {
        const site = resolveSite(ctx.sites, args.site);
        const data = (await callDesignCore(site, 'POST', '/design-intelligence/preview', {
          brief: args.brief,
          page_id: args.page_id ?? 0,
          product_type: args.product_type,
          mode: args.mode,
          variance: args.variance,
          motion: args.motion,
          density: args.density,
          bindings: args.bindings,
          adapter_target: args.adapter_target,
        })) as DesignPreviewResponse;
        return jsonResult(args.detail ? data : compact(data));
      })
  );
}
