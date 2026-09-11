import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import type { Server } from 'node:http';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';
import { createApp } from '../src/server.js';
import type { BridgeContext } from '../src/context.js';

/**
 * Real local MCP negative-contract gate, run through the REAL HTTP transport -- never
 * InMemoryTransport, never a mocked fetch. Two of the five contracts (unknown site, missing
 * confirm, stale preview/page conflict) run against the same already-running design-core-mcp
 * container local-e2e.test.ts uses. The other two (allowWrite=false, wrong machine credential)
 * need a site configuration the shared container doesn't have, so they spin up a throwaway
 * second instance of the real, compiled server (createApp() + a genuine http.Server on an
 * OS-assigned port) that reaches the same WordPress over its host-mapped port instead of the
 * Docker-internal hostname -- still the real Express app, the real StreamableHTTPServerTransport,
 * and a real StreamableHTTPClientTransport driving it; only the network path to WordPress
 * differs from the primary container.
 *
 * Gated identically to local-e2e.test.ts (set RUN_LOCAL_MCP_E2E=1 to run). Additionally needs:
 *   MCP_E2E_WP_TOKEN     - the real Design Core machine credential (same one the running
 *                          container uses), reused read-only for the allowWrite=false bridge.
 *   MCP_E2E_WP_HOST_URL  - WordPress reachable from this Node process, default
 *                          http://127.0.0.1:8087 (the host-mapped port; this process runs
 *                          outside Docker, so it cannot resolve the internal `wordpress` name).
 *
 * Safety: creates and owns exactly one disposable WordPress page, marked
 * _design_core_mcp_e2e_test=1. Cleanup re-checks that marker before ever deleting anything and
 * never touches page ID 2, "Air Consolidation", or any page it did not create itself.
 */

const RUN = process.env.RUN_LOCAL_MCP_E2E === '1';
const MCP_URL = process.env.MCP_E2E_URL ?? 'http://127.0.0.1:3000/mcp';
const INBOUND_TOKEN = process.env.MCP_E2E_INBOUND_TOKEN ?? '';
const WP_CLI = (process.env.MCP_E2E_WPCLI ?? 'docker compose run --rm wpcli').split(' ');
const WP_CLI_CWD = process.env.MCP_E2E_WPCLI_CWD;
const WP_TOKEN = process.env.MCP_E2E_WP_TOKEN ?? '';
const WP_HOST_URL = process.env.MCP_E2E_WP_HOST_URL ?? 'http://127.0.0.1:8087';

function wp(evalPhp: string): string {
  const args = [...WP_CLI.slice(1), 'eval', evalPhp];
  return execFileSync(WP_CLI[0] as string, args, { cwd: WP_CLI_CWD, encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'] }).trim();
}

async function startThrowawayBridge(ctx: BridgeContext, token: string): Promise<{ server: Server; baseUrl: URL }> {
  const app = createApp(ctx, { mode: 'token', token });
  let server!: Server;
  await new Promise<void>((resolve) => {
    server = app.listen(0, '127.0.0.1', () => resolve());
  });
  const address = server.address();
  if (!address || typeof address === 'string') throw new Error('expected a real ephemeral port');
  return { server, baseUrl: new URL(`http://127.0.0.1:${address.port}/mcp`) };
}

function buildMinimalIr(headingText: string) {
  return {
    schema_version: 4,
    type: 'design-ir',
    nodes: [
      {
        id: 'neg-root',
        source: { tag: 'section', classes: ['dc-mcp-neg'], attributes: {}, dom_path: '/section[1]' },
        semantic: { role: 'section', component_type: '', confidence: 1 },
        content: { text: '', rich_text: '', link: {}, image: {}, list: [], fields: {} },
        layout: { display: 'flex' },
        style: {},
        spacing: {},
        responsive: {},
        assets: {},
        interaction: {},
        component: { fingerprint: { version: 2, semantic: 'section', structure: 'section', content_schema: [], layout: 'flex', interaction: '' }, repeated: false, reusable: false, dynamic: false, content_schema: [] },
        children: ['neg-heading'],
      },
      {
        id: 'neg-heading',
        source: { tag: 'h2', classes: [], attributes: {}, dom_path: '/section[1]/h2[1]' },
        semantic: { role: 'heading', component_type: '', confidence: 1 },
        content: { text: headingText, rich_text: '', link: {}, image: {}, list: [], fields: {} },
        layout: {},
        style: {},
        spacing: {},
        responsive: {},
        assets: {},
        interaction: {},
        component: { fingerprint: { version: 2, semantic: 'heading', structure: 'heading', content_schema: [], layout: '', interaction: '' }, repeated: false, reusable: false, dynamic: false, content_schema: [] },
        children: [],
      },
    ],
    root_ids: ['neg-root'],
    analysis_quality: {},
    tokens: {},
    diagnostics: {},
  };
}

function parsePreview(result: unknown): { preview_id: string; plan_hash: string } {
  const text = ((result as { content?: Array<{ type: string; text: string }> }).content ?? [])[0]?.text ?? '{}';
  const parsed = JSON.parse(text) as { preview_id?: string; plan_hash?: string };
  if (!parsed.preview_id || !parsed.plan_hash) throw new Error(`expected preview_id + plan_hash, got ${text}`);
  return { preview_id: parsed.preview_id, plan_hash: parsed.plan_hash };
}

test(
  'real local MCP negative contracts: unknown site, missing confirm, allowWrite=false, wrong credential, stale preview conflict',
  { skip: !RUN },
  async (t) => {
    if (!RUN) return;
    assert.ok(INBOUND_TOKEN, 'MCP_E2E_INBOUND_TOKEN must be set to run this gate');
    assert.ok(WP_TOKEN, 'MCP_E2E_WP_TOKEN must be set to run this gate (the real Design Core machine credential)');

    const rand = randomBytes(4).toString('hex');
    const title = `Design Core MCP Negative E2E ${new Date().toISOString()} ${rand}`;

    let pageId = 0;
    try {
      await t.test('setup: create the disposable page through standard WordPress APIs', () => {
        const out = wp(
          `$id = wp_insert_post(array('post_type'=>'page','post_title'=>${JSON.stringify(title)},'post_content'=>'DC_MCP_NEG_BEFORE','post_status'=>'draft'), true); ` +
            `if (is_wp_error($id)) { fwrite(STDERR, $id->get_error_message()); exit(1); } ` +
            `update_post_meta($id, '_design_core_mcp_e2e_test', '1'); echo (int) $id;`
        );
        pageId = parseInt(out, 10);
        assert.ok(pageId > 0 && pageId !== 2, `expected a fresh disposable page ID, got ${out}`);
      });

      const client = new Client({ name: 'local-e2e-negative-test-client', version: '0.0.0' });
      const transport = new StreamableHTTPClientTransport(new URL(MCP_URL), { requestInit: { headers: { Authorization: `Bearer ${INBOUND_TOKEN}` } } });
      await client.connect(transport);

      await t.test('unknown site: refused with an error, never a raw-URL/arbitrary-host request', async () => {
        // With >=1 site configured, `site` is a closed zod enum of exactly the configured
        // names (see siteField() in context.ts), so an unrecognized value is rejected by
        // MCP input-schema validation before the tool handler -- and therefore before
        // resolveSite()/UnknownSiteError -- ever runs. Stronger than a runtime check, but
        // still surfaces the same way to the caller: an MCP tool error, zero network call.
        const result = await client.callTool({ name: 'design_core_site_status', arguments: { site: 'this-site-does-not-exist' } });
        assert.equal(result.isError, true, 'unknown site must be an MCP tool error');
        const text = (result.content as Array<{ type: string; text: string }>)[0]?.text ?? '';
        assert.match(text, /invalid/i);
      });

      await t.test('missing confirm: rejected by schema validation before any mutation', async () => {
        const result = await client.callTool({
          name: 'design_core_update_page',
          arguments: { site: 'local', page_id: pageId, preview_id: 'pv_placeholder', plan_hash: 'placeholder' },
        });
        assert.equal(result.isError, true, 'missing confirm must be an MCP tool error');
        const text = (result.content as Array<{ type: string; text: string }>)[0]?.text ?? '';
        assert.match(text, /confirm/i);
        const content = wp(`echo get_post(${pageId})->post_content;`);
        assert.equal(content, 'DC_MCP_NEG_BEFORE', 'page content must be untouched after a rejected missing-confirm call');
      });

      await t.test('allowWrite=false: denied at the bridge, zero Design Core mutation request', async () => {
        const ctx: BridgeContext = {
          siteNames: ['negtestro'],
          sites: { negtestro: { name: 'negtestro', baseUrl: WP_HOST_URL, token: WP_TOKEN, environment: 'local', allowWrite: false } },
        };
        const { server, baseUrl } = await startThrowawayBridge(ctx, 'negative-test-inbound-token-ro');
        try {
          const roTransport = new StreamableHTTPClientTransport(baseUrl, { requestInit: { headers: { Authorization: 'Bearer negative-test-inbound-token-ro' } } });
          const roClient = new Client({ name: 'local-e2e-negative-ro-client', version: '0.0.0' });
          await roClient.connect(roTransport);
          const result = await roClient.callTool({
            name: 'design_core_update_page',
            arguments: { site: 'negtestro', page_id: pageId, preview_id: 'pv_placeholder', plan_hash: 'placeholder', confirm: true },
          });
          assert.equal(result.isError, true, 'allowWrite:false must deny the write at the bridge');
          const text = (result.content as Array<{ type: string; text: string }>)[0]?.text ?? '';
          assert.match(text, /[Ww]rite guard/);
          await roClient.close();
        } finally {
          await new Promise<void>((resolve) => server.close(() => resolve()));
        }
        const content = wp(`echo get_post(${pageId})->post_content;`);
        assert.equal(content, 'DC_MCP_NEG_BEFORE', 'page content must be untouched after a bridge-level write-guard denial');
      });

      await t.test('wrong WordPress machine credential: authentication error, no mutation, no leaked token', async () => {
        const ctx: BridgeContext = {
          siteNames: ['negtestbadauth'],
          sites: { negtestbadauth: { name: 'negtestbadauth', baseUrl: WP_HOST_URL, token: 'dc_intentionally_invalid_machine_credential_value', environment: 'local', allowWrite: true } },
        };
        const { server, baseUrl } = await startThrowawayBridge(ctx, 'negative-test-inbound-token-badauth');
        try {
          const badTransport = new StreamableHTTPClientTransport(baseUrl, { requestInit: { headers: { Authorization: 'Bearer negative-test-inbound-token-badauth' } } });
          const badClient = new Client({ name: 'local-e2e-negative-badauth-client', version: '0.0.0' });
          await badClient.connect(badTransport);
          const result = await badClient.callTool({
            name: 'design_core_update_page',
            arguments: { site: 'negtestbadauth', page_id: pageId, preview_id: 'pv_placeholder', plan_hash: 'placeholder', confirm: true },
          });
          assert.equal(result.isError, true, 'a wrong WordPress machine credential must surface as an MCP tool error, never a silent success');
          const text = (result.content as Array<{ type: string; text: string }>)[0]?.text ?? '';
          assert.ok(!text.includes(WP_TOKEN), 'the real WordPress machine credential must never leak into an error result');
          assert.ok(!text.includes('dc_intentionally_invalid_machine_credential_value'), 'the wrong credential value itself must never leak into an error result');
          await badClient.close();
        } finally {
          await new Promise<void>((resolve) => server.close(() => resolve()));
        }
        const content = wp(`echo get_post(${pageId})->post_content;`);
        assert.equal(content, 'DC_MCP_NEG_BEFORE', 'page content must be untouched after a rejected wrong-credential request');
      });

      await t.test('stale preview/page conflict: fail closed, no blind overwrite', async () => {
        const previewA = await client.callTool({
          name: 'design_core_preview_build',
          arguments: { site: 'local', page_id: pageId, design_ir: buildMinimalIr('DC_MCP_NEG_A'), detail: true },
        });
        assert.equal(previewA.isError, undefined, `preview A: PASS (error: ${JSON.stringify(previewA.content)})`);
        const previewB = await client.callTool({
          name: 'design_core_preview_build',
          arguments: { site: 'local', page_id: pageId, design_ir: buildMinimalIr('DC_MCP_NEG_B'), detail: true },
        });
        assert.equal(previewB.isError, undefined, `preview B: PASS (error: ${JSON.stringify(previewB.content)})`);
        const a = parsePreview(previewA);
        const b = parsePreview(previewB);

        const applyA = await client.callTool({
          name: 'design_core_update_page',
          arguments: { site: 'local', page_id: pageId, preview_id: a.preview_id, plan_hash: a.plan_hash, confirm: true },
        });
        assert.equal(applyA.isError, undefined, `apply A: PASS (error: ${JSON.stringify(applyA.content)})`);

        const applyB = await client.callTool({
          name: 'design_core_update_page',
          arguments: { site: 'local', page_id: pageId, preview_id: b.preview_id, plan_hash: b.plan_hash, confirm: true },
        });
        assert.equal(applyB.isError, true, 'applying a preview built against a since-changed page must fail closed, not blindly overwrite');
        const text = (applyB.content as Array<{ type: string; text: string }>)[0]?.text ?? '';
        assert.match(text, /conflict/i);

        const rendered = wp(
          `echo class_exists('\\\\Elementor\\\\Plugin') ? (string) \\Elementor\\Plugin::instance()->frontend->get_builder_content_for_display(${pageId}, true) : '';`
        );
        assert.ok(rendered.includes('DC_MCP_NEG_A'), 'the page must still reflect the successfully-applied A, not B');
        assert.ok(!rendered.includes('DC_MCP_NEG_B'), 'the rejected stale preview B must never have been applied');
      });

      await client.close();
    } finally {
      if (pageId) {
        const marker = wp(`echo get_post_meta(${pageId}, '_design_core_mcp_e2e_test', true);`);
        if (marker === '1') {
          wp(`wp_delete_post(${pageId}, true); echo 'deleted';`);
          const gone = wp(`echo get_post(${pageId}) ? '1' : '0';`);
          assert.equal(gone, '0', 'cleanup: disposable page deleted');
        } else {
          assert.fail(`cleanup refused: ownership marker missing on page ${pageId} (marker=${JSON.stringify(marker)})`);
        }
      }
    }
  }
);
