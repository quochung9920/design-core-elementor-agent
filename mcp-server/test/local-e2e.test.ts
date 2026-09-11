import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';

/**
 * Real local MCP end-to-end gate: MCP Client -(real Streamable HTTP)-> the actually-running
 * design-core-mcp container -> Design Core REST v2 -> WordPress -> Design Core -> Elementor.
 *
 * This is NOT a unit test and is intentionally skipped unless the real Docker stack (Phase 3)
 * is actually running with a real machine credential and inbound token configured -- CI has
 * neither, and this must never block the ordinary `npm test` run. Set RUN_LOCAL_MCP_E2E=1 plus
 * MCP_E2E_URL / MCP_E2E_INBOUND_TOKEN (and have `docker compose` reachable for the wp-cli calls
 * this makes to create/verify/clean up its own disposable page) to actually run it.
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

function wp(evalPhp: string): string {
  const args = [...WP_CLI.slice(1), 'eval', evalPhp];
  return execFileSync(WP_CLI[0] as string, args, { cwd: WP_CLI_CWD, encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'] }).trim();
}

test('real local MCP E2E: site_status -> snapshot -> preview -> update -> render -> history -> rollback -> cleanup', { skip: !RUN }, async (t) => {
  if (!RUN) return;
  assert.ok(INBOUND_TOKEN, 'MCP_E2E_INBOUND_TOKEN must be set to run this gate');

  const rand = randomBytes(4).toString('hex');
  const beforeMarker = `DC_MCP_BEFORE_${rand}`;
  const afterMarker = `DC_MCP_AFTER_${rand}`;
  const title = `Design Core MCP E2E ${new Date().toISOString()} ${rand}`;

  let pageId = 0;
  try {
    await t.test('Phase 5: create the disposable page through standard WordPress APIs', () => {
      const out = wp(
        `$id = wp_insert_post(array('post_type'=>'page','post_title'=>${JSON.stringify(title)},'post_content'=>${JSON.stringify(beforeMarker)},'post_status'=>'draft'), true); ` +
          `if (is_wp_error($id)) { fwrite(STDERR, $id->get_error_message()); exit(1); } ` +
          `update_post_meta($id, '_design_core_mcp_e2e_test', '1'); echo (int) $id;`
      );
      pageId = parseInt(out, 10);
      assert.ok(pageId > 0 && pageId !== 2, `expected a fresh disposable page ID, got ${out}`);
      const marker = wp(`echo get_post_meta(${pageId}, '_design_core_mcp_e2e_test', true);`);
      assert.equal(marker, '1');
    });

    const beforeHash = wp(
      `$p = get_post(${pageId}); $s = array('post'=>array('post_title'=>$p->post_title,'post_content'=>$p->post_content,'post_excerpt'=>$p->post_excerpt,'post_status'=>$p->post_status,'post_name'=>$p->post_name,'post_parent'=>$p->post_parent,'menu_order'=>$p->menu_order,'comment_status'=>$p->comment_status,'ping_status'=>$p->ping_status),'meta'=>array()); ` +
        `foreach (array('_elementor_data','_elementor_edit_mode','_elementor_version','_elementor_template_type') as $k) { $e = metadata_exists('post', ${pageId}, $k); $s['meta'][$k] = array('exists'=>$e,'value'=>$e?get_post_meta(${pageId},$k,true):null); } ` +
        `function dc_e2e_sort($v) { if (!is_array($v)) return $v; $o = array(); foreach ($v as $k=>$i) { $o[$k]=dc_e2e_sort($i); } ksort($o); return $o; } ` +
        `echo hash('sha256', wp_json_encode(dc_e2e_sort($s)));`
    );
    console.log(`PAGE_ID=${pageId}`);
    console.log(`BEFORE_HASH=${beforeHash}`);

    const transport = new StreamableHTTPClientTransport(new URL(MCP_URL), { requestInit: { headers: { Authorization: `Bearer ${INBOUND_TOKEN}` } } });
    const client = new Client({ name: 'local-e2e-test-client', version: '0.0.0' });
    await client.connect(transport);

    await t.test('Phase 6: real MCP read flow (site_status, page_snapshot, preview_build)', async () => {
      const status = await client.callTool({ name: 'design_core_site_status', arguments: { site: 'local' } });
      assert.equal(status.isError, undefined, 'design_core_site_status: PASS');

      const snapshot = await client.callTool({ name: 'design_core_page_snapshot', arguments: { site: 'local', page_id: pageId } });
      assert.equal(snapshot.isError, undefined, 'design_core_page_snapshot: PASS');
    });

    const designIr = {
      schema_version: 4,
      type: 'design-ir',
      nodes: [
        {
          id: 'e2e-root',
          source: { tag: 'section', classes: ['dc-mcp-e2e'], attributes: {}, dom_path: '/section[1]' },
          semantic: { role: 'section', component_type: '', confidence: 1 },
          content: { text: '', rich_text: '', link: {}, image: {}, list: [], fields: {} },
          layout: { display: 'flex' },
          style: {},
          spacing: {},
          responsive: {},
          assets: {},
          interaction: {},
          component: { fingerprint: { version: 2, semantic: 'section', structure: 'section', content_schema: [], layout: 'flex', interaction: '' }, repeated: false, reusable: false, dynamic: false, content_schema: [] },
          children: ['e2e-heading', 'e2e-text'],
        },
        {
          id: 'e2e-heading',
          source: { tag: 'h2', classes: [], attributes: {}, dom_path: '/section[1]/h2[1]' },
          semantic: { role: 'heading', component_type: '', confidence: 1 },
          content: { text: afterMarker, rich_text: '', link: {}, image: {}, list: [], fields: {} },
          layout: {},
          style: {},
          spacing: {},
          responsive: {},
          assets: {},
          interaction: {},
          component: { fingerprint: { version: 2, semantic: 'heading', structure: 'heading', content_schema: [], layout: '', interaction: '' }, repeated: false, reusable: false, dynamic: false, content_schema: [] },
          children: [],
        },
        {
          id: 'e2e-text',
          source: { tag: 'p', classes: [], attributes: {}, dom_path: '/section[1]/p[1]' },
          semantic: { role: 'text', component_type: '', confidence: 1 },
          content: { text: 'MCP end-to-end verification', rich_text: 'MCP end-to-end verification', link: {}, image: {}, list: [], fields: {} },
          layout: {},
          style: {},
          spacing: {},
          responsive: {},
          assets: {},
          interaction: {},
          component: { fingerprint: { version: 2, semantic: 'text', structure: 'text', content_schema: [], layout: '', interaction: '' }, repeated: false, reusable: false, dynamic: false, content_schema: [] },
          children: [],
        },
      ],
      root_ids: ['e2e-root'],
      analysis_quality: {},
      tokens: {},
      diagnostics: {},
    };

    let previewId = '';
    let planHash = '';
    await t.test('Phase 6b: design_core_preview_build produces preview_id + plan_hash from a real Design IR (no raw Elementor JSON sent)', async () => {
      const preview = await client.callTool({ name: 'design_core_preview_build', arguments: { site: 'local', page_id: pageId, design_ir: designIr, detail: true } });
      assert.equal(preview.isError, undefined, `design_core_preview_build: PASS (error: ${JSON.stringify(preview.content)})`);
      const text = (preview.content as Array<{ type: string; text: string }>)[0]?.text ?? '{}';
      const parsed = JSON.parse(text) as { preview_id?: string; plan_hash?: string };
      assert.ok(parsed.preview_id, 'preview_id present');
      assert.ok(parsed.plan_hash, 'plan_hash present');
      previewId = parsed.preview_id as string;
      planHash = parsed.plan_hash as string;
    });

    await t.test('Phase 7: real MCP write flow (design_core_update_page, confirm:true)', async () => {
      const update = await client.callTool({
        name: 'design_core_update_page',
        arguments: { site: 'local', page_id: pageId, preview_id: previewId, plan_hash: planHash, confirm: true },
      });
      assert.equal(update.isError, undefined, `design_core_update_page: PASS (error: ${JSON.stringify(update.content)})`);
    });

    await t.test('Phase 7b: verify independently through WordPress/Elementor (reload + real frontend render)', () => {
      const stillExists = wp(`echo get_post(${pageId}) ? '1' : '0';`);
      assert.equal(stillExists, '1', 'page still exists after MCP update');
      const rendered = wp(
        `echo class_exists('\\\\Elementor\\\\Plugin') ? (string) \\Elementor\\Plugin::instance()->frontend->get_builder_content_for_display(${pageId}, true) : '';`
      );
      assert.ok(rendered.includes(afterMarker), `rendered output should contain ${afterMarker}`);
      assert.ok(!rendered.includes(beforeMarker), 'rendered output should not contain the stale BEFORE marker');
    });

    const afterHash = wp(
      `$p = get_post(${pageId}); $s = array('post'=>array('post_title'=>$p->post_title,'post_content'=>$p->post_content,'post_excerpt'=>$p->post_excerpt,'post_status'=>$p->post_status,'post_name'=>$p->post_name,'post_parent'=>$p->post_parent,'menu_order'=>$p->menu_order,'comment_status'=>$p->comment_status,'ping_status'=>$p->ping_status),'meta'=>array()); ` +
        `foreach (array('_elementor_data','_elementor_edit_mode','_elementor_version','_elementor_template_type') as $k) { $e = metadata_exists('post', ${pageId}, $k); $s['meta'][$k] = array('exists'=>$e,'value'=>$e?get_post_meta(${pageId},$k,true):null); } ` +
        `function dc_e2e_sort2($v) { if (!is_array($v)) return $v; $o = array(); foreach ($v as $k=>$i) { $o[$k]=dc_e2e_sort2($i); } ksort($o); return $o; } ` +
        `echo hash('sha256', wp_json_encode(dc_e2e_sort2($s)));`
    );
    console.log(`AFTER_HASH=${afterHash}`);
    assert.notEqual(afterHash, beforeHash, 'AFTER_HASH != BEFORE_HASH');

    let entryId = '';
    await t.test('Phase 8: design_core_history reports a real, rollback-available entry for this page', async () => {
      const history = await client.callTool({ name: 'design_core_history', arguments: { site: 'local', page_id: pageId, limit: 10 } });
      assert.equal(history.isError, undefined, 'design_core_history: PASS');
      const text = (history.content as Array<{ type: string; text: string }>)[0]?.text ?? '[]';
      const entries = JSON.parse(text) as Array<{ id: string; rollback_available: boolean; rolled_back_at?: string }>;
      const live = entries.find((e) => e.rollback_available && !e.rolled_back_at);
      assert.ok(live, `expected a real, rollback-available history entry; got ${text}`);
      entryId = (live as { id: string }).id;
    });

    await t.test('Phase 9: design_core_rollback (confirm:true) through the real HTTP MCP bridge', async () => {
      const rollback = await client.callTool({ name: 'design_core_rollback', arguments: { site: 'local', entry_id: entryId, confirm: true } });
      assert.equal(rollback.isError, undefined, `design_core_rollback: PASS (error: ${JSON.stringify(rollback.content)})`);
    });

    await t.test('Phase 10: exact restoration verified independently through WordPress', () => {
      const restoredHash = wp(
        `$p = get_post(${pageId}); $s = array('post'=>array('post_title'=>$p->post_title,'post_content'=>$p->post_content,'post_excerpt'=>$p->post_excerpt,'post_status'=>$p->post_status,'post_name'=>$p->post_name,'post_parent'=>$p->post_parent,'menu_order'=>$p->menu_order,'comment_status'=>$p->comment_status,'ping_status'=>$p->ping_status),'meta'=>array()); ` +
          `foreach (array('_elementor_data','_elementor_edit_mode','_elementor_version','_elementor_template_type') as $k) { $e = metadata_exists('post', ${pageId}, $k); $s['meta'][$k] = array('exists'=>$e,'value'=>$e?get_post_meta(${pageId},$k,true):null); } ` +
          `function dc_e2e_sort3($v) { if (!is_array($v)) return $v; $o = array(); foreach ($v as $k=>$i) { $o[$k]=dc_e2e_sort3($i); } ksort($o); return $o; } ` +
          `echo hash('sha256', wp_json_encode(dc_e2e_sort3($s)));`
      );
      console.log(`RESTORED_HASH=${restoredHash}`);
      assert.equal(restoredHash, beforeHash, 'RESTORED_HASH === BEFORE_HASH exactly, via governed Change_Ledger rollback through the real MCP bridge');
      const stillExists = wp(`echo get_post(${pageId}) ? '1' : '0';`);
      assert.equal(stillExists, '1', 'the existing page was never deleted by rollback');
    });

    await t.test('Phase 11: a redundant rollback on the same already-rolled-back entry is refused, with zero further mutation', async () => {
      // The compensated-failure contract itself (an injected internal restore/compensation
      // failure) is proven at the Change_Ledger unit level (p0-history-rollback-compensation.php)
      // and against real WordPress (tests/runtime/history-rollback-compensation.php) -- its
      // test-only fault-injection hook is deliberately process-local and non-remotely-triggerable
      // (see TEST_FAIL_STAGE_FILTER's docblock), so it cannot reach a live Apache worker serving
      // this MCP request from a separate process. What IS practical and valuable to prove here,
      // through the real HTTP bridge, is the adjacent contract: any error rollback() returns
      // (not only a compensated one) surfaces as a real MCP tool error, with zero further
      // mutation -- "no partial rollback" for a call that must not proceed at all.
      const restoredHashBefore = wp(
        `$p = get_post(${pageId}); $s = array('post'=>array('post_title'=>$p->post_title,'post_content'=>$p->post_content,'post_excerpt'=>$p->post_excerpt,'post_status'=>$p->post_status,'post_name'=>$p->post_name,'post_parent'=>$p->post_parent,'menu_order'=>$p->menu_order,'comment_status'=>$p->comment_status,'ping_status'=>$p->ping_status),'meta'=>array()); ` +
          `foreach (array('_elementor_data','_elementor_edit_mode','_elementor_version','_elementor_template_type') as $k) { $e = metadata_exists('post', ${pageId}, $k); $s['meta'][$k] = array('exists'=>$e,'value'=>$e?get_post_meta(${pageId},$k,true):null); } ` +
          `function dc_e2e_sort4($v) { if (!is_array($v)) return $v; $o = array(); foreach ($v as $k=>$i) { $o[$k]=dc_e2e_sort4($i); } ksort($o); return $o; } ` +
          `echo hash('sha256', wp_json_encode(dc_e2e_sort4($s)));`
      );
      // An explicit, distinct idempotency_key forces genuine re-execution rather than an
      // Idempotency-Store replay of Phase 9's identical-looking successful call -- this must
      // prove the real "already rolled back" guard fires, not just that a cached response repeats.
      const redundant = await client.callTool({
        name: 'design_core_rollback',
        arguments: { site: 'local', entry_id: entryId, confirm: true, idempotency_key: `redundant-rollback-check-${pageId}` },
      });
      assert.equal(redundant.isError, true, 'rolling back an already-rolled-back entry must be an MCP tool error, not a silent success');
      const text = (redundant.content as Array<{ type: string; text: string }>)[0]?.text ?? '';
      assert.match(text, /already.*rolled.*back/i, 'the error names the stable already-rolled-back condition');
      const restoredHashAfter = wp(
        `$p = get_post(${pageId}); $s = array('post'=>array('post_title'=>$p->post_title,'post_content'=>$p->post_content,'post_excerpt'=>$p->post_excerpt,'post_status'=>$p->post_status,'post_name'=>$p->post_name,'post_parent'=>$p->post_parent,'menu_order'=>$p->menu_order,'comment_status'=>$p->comment_status,'ping_status'=>$p->ping_status),'meta'=>array()); ` +
          `foreach (array('_elementor_data','_elementor_edit_mode','_elementor_version','_elementor_template_type') as $k) { $e = metadata_exists('post', ${pageId}, $k); $s['meta'][$k] = array('exists'=>$e,'value'=>$e?get_post_meta(${pageId},$k,true):null); } ` +
          `function dc_e2e_sort5($v) { if (!is_array($v)) return $v; $o = array(); foreach ($v as $k=>$i) { $o[$k]=dc_e2e_sort5($i); } ksort($o); return $o; } ` +
          `echo hash('sha256', wp_json_encode(dc_e2e_sort5($s)));`
      );
      assert.equal(restoredHashAfter, restoredHashBefore, 'the redundant rollback attempt made zero further changes to the page');
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
});
