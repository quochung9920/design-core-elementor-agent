import { z } from 'zod';
import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { BridgeContext } from '../context.js';
import { siteField } from '../context.js';
import { resolveSite } from '../site-config.js';
import { callDesignCore } from '../design-core-client.js';
import { guarded, jsonResult } from '../tool-result.js';

interface PageSnapshotResponse {
  page?: Record<string, unknown>;
  design_core?: Record<string, unknown>;
  elementor?: Record<string, unknown>;
  visual_qa?: Record<string, unknown>;
  visual_feedback?: Record<string, unknown>;
  history?: unknown[];
  warnings?: unknown[];
  [key: string]: unknown;
}

function compact(data: PageSnapshotResponse) {
  return {
    page: data.page,
    editor_mode: (data.elementor as { editor_mode?: unknown } | undefined)?.editor_mode,
    total_elements: (data.elementor as { total_elements?: unknown } | undefined)?.total_elements,
    widget_types: (data.elementor as { widget_types?: unknown } | undefined)?.widget_types,
    element_tree_hash: (data.elementor as { element_tree_hash?: unknown } | undefined)?.element_tree_hash,
    manifest_section_count: (data.design_core as { manifest_section_count?: unknown } | undefined)?.manifest_section_count,
    visual_qa_status: (data.visual_qa as { status?: unknown } | undefined)?.status,
    history_entry_count: Array.isArray(data.history) ? data.history.length : 0,
    warnings: data.warnings ?? [],
    note: 'Compact view. Pass detail:true for the full snapshot (widget profiles, content outline, full visual QA/history).',
  };
}

export function registerPageSnapshotTool(server: McpServer, ctx: BridgeContext): void {
  server.registerTool(
    'design_core_page_snapshot',
    {
      title: 'Design Core page snapshot',
      description:
        'READ. One normalized digest of a real Elementor page: manifest, element tree summary, widget types, tokens, visual QA and history. Performs no mutation.',
      inputSchema: {
        site: siteField(ctx.siteNames),
        page_id: z.number().int().positive().describe('WordPress page/post ID.'),
        detail: z.boolean().optional().default(false).describe('true returns the full snapshot; false (default) returns a compact summary.'),
      },
      annotations: { readOnlyHint: true, destructiveHint: false, openWorldHint: true, title: 'Page snapshot' },
    },
    async (args) =>
      guarded(async () => {
        const site = resolveSite(ctx.sites, args.site);
        const data = (await callDesignCore(site, 'GET', `/pages/${args.page_id}/snapshot`)) as PageSnapshotResponse;
        return jsonResult(args.detail ? data : compact(data));
      })
  );
}
