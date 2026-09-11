import { z } from 'zod';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { BridgeContext } from '../context.js';
import { siteField } from '../context.js';
import { resolveSite } from '../site-config.js';
import { callDesignCore } from '../design-core-client.js';
import { guarded, jsonResult } from '../tool-result.js';

interface PreviewResponse {
  preview_id?: unknown;
  plan_hash?: unknown;
  page_id?: unknown;
  current_page_hash?: unknown;
  expires_at?: unknown;
  can_execute_safely?: unknown;
  unsafe_reasons?: unknown;
  estimated_element_count?: unknown;
  strategy_counts?: unknown;
  requirements?: unknown;
  warnings?: unknown;
  items?: unknown;
  [key: string]: unknown;
}

function compact(data: PreviewResponse) {
  return {
    preview_id: data.preview_id,
    plan_hash: data.plan_hash,
    page_id: data.page_id,
    current_page_hash: data.current_page_hash,
    expires_at: data.expires_at,
    can_execute_safely: data.can_execute_safely,
    unsafe_reasons: data.unsafe_reasons,
    estimated_element_count: data.estimated_element_count,
    strategy_counts: data.strategy_counts,
    requirements: data.requirements,
    warnings: data.warnings,
    note: 'Compact view. Pass detail:true for the full per-item plan and widget candidate simulation. Use preview_id + plan_hash with design_core_update_page to execute.',
  };
}

export function registerPreviewBuildTool(server: McpServer, ctx: BridgeContext): void {
  server.registerTool(
    'design_core_preview_build',
    {
      title: 'Design Core build preview',
      description:
        'READ / PREVIEW. Dry-run a Design IR/HTML change through BuildPlan Preview with zero mutation to the page. Returns a preview_id + plan_hash approval ticket required by design_core_update_page. Provide either html (+ optional css) or design_ir, not both.',
      inputSchema: {
        site: siteField(ctx.siteNames),
        page_id: z.number().int().nonnegative().optional().describe('Existing page to bind this preview to (required before update_page can use it). Omit only for an exploratory preview with no target page yet.'),
        html: z.string().max(2_000_000).optional(),
        css: z.string().max(1_000_000).optional(),
        design_ir: z.record(z.string(), z.unknown()).optional().describe('A pre-built Design IR object, as an alternative to html/css.'),
        adapter_target: z.enum(['auto', 'elementor-v3', 'elementor-v4']).optional().default('auto'),
        detail: z.boolean().optional().default(false),
      },
      annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: true, title: 'Preview build (dry run)' },
    },
    async (args) =>
      guarded(async () => {
        const site = resolveSite(ctx.sites, args.site);
        const data = (await callDesignCore(site, 'POST', '/build/preview', {
          page_id: args.page_id ?? 0,
          html: args.html,
          css: args.css,
          design_ir: args.design_ir,
          adapter_target: args.adapter_target,
        })) as PreviewResponse;
        return jsonResult(args.detail ? data : compact(data));
      })
  );
}
