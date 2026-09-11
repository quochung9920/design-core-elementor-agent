import { test, before, after, mock } from 'node:test';
import assert from 'node:assert/strict';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { BridgeContext } from '../src/context.js';
import { registerSiteStatusTool } from '../src/tools/site-status.js';
import { registerPageSnapshotTool } from '../src/tools/page-snapshot.js';
import { registerPreviewBuildTool } from '../src/tools/preview-build.js';
import { registerPreviewFigmaTool } from '../src/tools/preview-figma.js';
import { registerRecommendDesignSystemTool } from '../src/tools/recommend-design-system.js';
import { registerPreviewDesignSystemTool } from '../src/tools/preview-design-system.js';
import { registerUpdatePageTool } from '../src/tools/update-page.js';
import { registerVisualFeedbackTool } from '../src/tools/visual-feedback.js';
import { registerAutoCorrectTool } from '../src/tools/auto-correct.js';
import { registerHistoryTool } from '../src/tools/history.js';
import { registerRollbackTool } from '../src/tools/rollback.js';
import { registerPublishPageTool } from '../src/tools/publish-page.js';

const READ_TOOLS = [
  'design_core_site_status',
  'design_core_page_snapshot',
  'design_core_preview_build',
  'design_core_preview_figma',
  'design_core_recommend_design_system',
  'design_core_preview_design_system',
  'design_core_visual_feedback',
  'design_core_history',
];
const WRITE_TOOLS = ['design_core_update_page', 'design_core_auto_correct', 'design_core_publish_page'];
const DESTRUCTIVE_TOOLS = ['design_core_rollback'];

const ctx: BridgeContext = {
  siteNames: ['local', 'blocked'],
  sites: {
    local: { name: 'local', baseUrl: 'http://wordpress', token: 'dcmcp_test_secret', environment: 'local', allowWrite: true },
    blocked: { name: 'blocked', baseUrl: 'http://wordpress-blocked', token: 'dcmcp_test_secret_blocked', environment: 'local', allowWrite: false },
  },
};

let client: Client;
let originalFetch: typeof fetch;

before(async () => {
  originalFetch = globalThis.fetch;
  const server = new McpServer({ name: 'test', version: '0.0.0' });
  registerSiteStatusTool(server, ctx);
  registerPageSnapshotTool(server, ctx);
  registerPreviewBuildTool(server, ctx);
  registerPreviewFigmaTool(server, ctx);
  registerRecommendDesignSystemTool(server, ctx);
  registerPreviewDesignSystemTool(server, ctx);
  registerUpdatePageTool(server, ctx);
  registerVisualFeedbackTool(server, ctx);
  registerAutoCorrectTool(server, ctx);
  registerHistoryTool(server, ctx);
  registerRollbackTool(server, ctx);
  registerPublishPageTool(server, ctx);

  const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();
  client = new Client({ name: 'test-client', version: '0.0.0' });
  await Promise.all([client.connect(clientTransport), server.connect(serverTransport)]);
});

after(() => {
  globalThis.fetch = originalFetch;
});

test('exposes exactly the twelve documented tools, each with a distinct name', async () => {
  const { tools } = await client.listTools();
  const names = tools.map((t) => t.name).sort();
  assert.deepEqual(names, [...READ_TOOLS, ...WRITE_TOOLS, ...DESTRUCTIVE_TOOLS].sort());
});

test('read tools are annotated readOnlyHint:true, destructiveHint:false', async () => {
  const { tools } = await client.listTools();
  for (const name of READ_TOOLS) {
    const tool = tools.find((t) => t.name === name);
    assert.ok(tool, `${name} should be registered`);
    assert.equal(tool!.annotations?.readOnlyHint, true, `${name} should be readOnlyHint:true`);
    assert.notEqual(tool!.annotations?.destructiveHint, true, `${name} should not be destructive`);
  }
});

test('write tools are annotated readOnlyHint:false, destructiveHint:false, and require confirm:true in their schema', async () => {
  const { tools } = await client.listTools();
  for (const name of WRITE_TOOLS) {
    const tool = tools.find((t) => t.name === name);
    assert.ok(tool, `${name} should be registered`);
    assert.equal(tool!.annotations?.readOnlyHint, false, `${name} should be readOnlyHint:false`);
    assert.notEqual(tool!.annotations?.destructiveHint, true, `${name} should not be marked destructive`);
    const confirmSchema = (tool!.inputSchema as { properties?: Record<string, unknown> }).properties?.confirm;
    assert.ok(confirmSchema, `${name} must declare a confirm field`);
  }
});

test('the rollback tool is the only one annotated destructiveHint:true', async () => {
  const { tools } = await client.listTools();
  const destructive = tools.filter((t) => t.annotations?.destructiveHint === true).map((t) => t.name);
  assert.deepEqual(destructive, DESTRUCTIVE_TOOLS);
});

test('calling a write tool without confirm:true is rejected by schema validation before any network call happens', async () => {
  const fetchMock = mock.fn(() => {
    throw new Error('fetch should never be called when confirm is missing');
  });
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  const result = await client.callTool({
    name: 'design_core_update_page',
    arguments: { site: 'local', page_id: 1, preview_id: 'pv_1', plan_hash: 'h1' },
  });
  assert.equal(result.isError, true, 'the SDK reports a missing/invalid confirm as a tool error result, not a thrown exception');
  const text = (result.content as Array<{ type: string; text: string }>)[0]?.text ?? '';
  assert.match(text, /confirm/);
  assert.equal(fetchMock.mock.callCount(), 0, 'Zod input validation must happen before the handler ever runs');
});

test('targeting an unconfigured site is refused without any network call (no arbitrary URL/site targeting)', async () => {
  const fetchMock = mock.fn(() => {
    throw new Error('fetch should never be called for an unknown site');
  });
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  const result = await client.callTool({ name: 'design_core_site_status', arguments: { site: 'production-that-does-not-exist' } });
  assert.equal(result.isError, true);
  assert.equal(fetchMock.mock.callCount(), 0);
});

test('a read tool call reaches Design Core with the configured Bearer token and returns its JSON', async () => {
  const fetchMock = mock.fn(async (url: string, init?: RequestInit) => {
    assert.equal(url, 'http://wordpress/wp-json/design-core-elementor/v2/site/status');
    assert.equal((init?.headers as Record<string, string>).Authorization, 'Bearer dcmcp_test_secret');
    return new Response(JSON.stringify({ plugin_version: '1.0.0-rc21', write_enabled: true }), { status: 200 });
  });
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  const result = await client.callTool({ name: 'design_core_site_status', arguments: { site: 'local' } });
  assert.equal(result.isError, undefined);
  const text = (result.content as Array<{ type: string; text: string }>)[0]?.text ?? '';
  assert.match(text, /1\.0\.0-rc21/);
  assert.equal(fetchMock.mock.callCount(), 1);
});

test('design system recommendation is a read-only pass-through to the Design Core intelligence endpoint', async () => {
  const fetchMock = mock.fn(async (url: string, init?: RequestInit) => {
    assert.equal(url, 'http://wordpress/wp-json/design-core-elementor/v2/design-intelligence/recommend');
    assert.equal((init?.headers as Record<string, string>).Authorization, 'Bearer dcmcp_test_secret');
    const body = JSON.parse(String(init?.body ?? '{}')) as { brief?: string; product_type?: string };
    assert.match(body.brief ?? '', /engineering/i);
    return new Response(
      JSON.stringify({ status: 'success', profile: { product: { id: 'b2b-service' }, direction: {}, page_strategy: {}, tokens: {}, ux: {} }, mutation: false }),
      { status: 200 }
    );
  });
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  const result = await client.callTool({
    name: 'design_core_recommend_design_system',
    arguments: { site: 'local', brief: 'International engineering and construction B2B service' },
  });
  assert.equal(result.isError, undefined);
  const text = (result.content as Array<{ type: string; text: string }>)[0]?.text ?? '';
  assert.match(text, /b2b-service/);
  assert.equal(fetchMock.mock.callCount(), 1);
});

test('design system exploratory preview is read-only and remains unbound to a real page', async () => {
  const fetchMock = mock.fn(async (url: string, init?: RequestInit) => {
    assert.equal(url, 'http://wordpress/wp-json/design-core-elementor/v2/design-intelligence/preview');
    const body = JSON.parse(String(init?.body ?? '{}')) as { page_id?: number; brief?: string; bindings?: unknown };
    assert.equal(body.page_id, 0);
    assert.equal(body.bindings, undefined);
    assert.match(body.brief ?? '', /b2b/i);
    return new Response(JSON.stringify({ preview_id: 'pv_design', plan_hash: 'h_design', page_id: 0, exploratory_only: true, mutation: false }), { status: 200 });
  });
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  const result = await client.callTool({
    name: 'design_core_preview_design_system',
    arguments: { site: 'local', brief: 'B2B engineering service' },
  });
  assert.equal(result.isError, undefined);
  const text = (result.content as Array<{ type: string; text: string }>)[0]?.text ?? '';
  assert.match(text, /Exploratory-only preview/);
  assert.equal(fetchMock.mock.callCount(), 1);
});

test('a write tool call with confirm:true sends an Idempotency-Key header', async () => {
  const fetchMock = mock.fn(async (url: string, init?: RequestInit) => {
    assert.equal(url, 'http://wordpress/wp-json/design-core-elementor/v2/pages/42/update');
    const headers = init?.headers as Record<string, string>;
    assert.ok(headers['Idempotency-Key'] && headers['Idempotency-Key'].length > 0);
    return new Response(JSON.stringify({ status: 'success', page_id: 42, rollback_available: true }), { status: 200 });
  });
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  const result = await client.callTool({
    name: 'design_core_update_page',
    arguments: { site: 'local', page_id: 42, preview_id: 'pv_1', plan_hash: 'h1', confirm: true },
  });
  assert.equal(result.isError, undefined);
  assert.equal(fetchMock.mock.callCount(), 1);
});

test('a Design Core error response (e.g. conflict) is surfaced as an MCP tool error, not a crash', async () => {
  const fetchMock = mock.fn(
    async () => new Response(JSON.stringify({ code: 'design_core_page_conflict', message: 'The page changed since this preview.' }), { status: 409 })
  );
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  const result = await client.callTool({
    name: 'design_core_update_page',
    arguments: { site: 'local', page_id: 42, preview_id: 'pv_1', plan_hash: 'h1', confirm: true },
  });
  assert.equal(result.isError, true);
  const text = (result.content as Array<{ type: string; text: string }>)[0]?.text ?? '';
  assert.match(text, /409/);
  assert.match(text, /page changed/);
});

// Bridge-level write guard: the 'blocked' site (allowWrite:false) must deny every mutation
// tool before any network I/O, through the full registered-tool handler path (MCP Client +
// InMemoryTransport), not just a direct unit call to assertSiteWriteEnabled().
test('design_core_update_page is denied at the bridge with zero network calls when allowWrite:false', async () => {
  const fetchMock = mock.fn(() => {
    throw new Error('fetch should never be called when allowWrite is false');
  });
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  const result = await client.callTool({
    name: 'design_core_update_page',
    arguments: { site: 'blocked', page_id: 1, preview_id: 'pv_1', plan_hash: 'h1', confirm: true },
  });
  assert.equal(result.isError, true, 'a denied write must be an MCP error result, not a success');
  const text = (result.content as Array<{ type: string; text: string }>)[0]?.text ?? '';
  assert.match(text, /design_core_writes_disabled/, 'the stable write-guard code must be present in the error text');
  assert.equal(fetchMock.mock.callCount(), 0, 'writes disabled must make zero network calls');
});

test('design_core_auto_correct is denied at the bridge with zero network calls when allowWrite:false', async () => {
  const fetchMock = mock.fn(() => {
    throw new Error('fetch should never be called when allowWrite is false');
  });
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  const result = await client.callTool({
    name: 'design_core_auto_correct',
    arguments: { site: 'blocked', page_id: 1, reference_url: 'https://example.test/reference.png', confirm: true },
  });
  assert.equal(result.isError, true, 'a denied write must be an MCP error result, not a success');
  const text = (result.content as Array<{ type: string; text: string }>)[0]?.text ?? '';
  assert.match(text, /design_core_writes_disabled/, 'the stable write-guard code must be present in the error text');
  assert.equal(fetchMock.mock.callCount(), 0, 'writes disabled must make zero network calls');
});

test('design_core_publish_page is denied at the bridge with zero network calls when allowWrite:false', async () => {
  const fetchMock = mock.fn(() => {
    throw new Error('fetch should never be called when allowWrite is false');
  });
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  const result = await client.callTool({
    name: 'design_core_publish_page',
    arguments: { site: 'blocked', page_id: 1, confirm: true },
  });
  assert.equal(result.isError, true, 'a denied write must be an MCP error result, not a success');
  const text = (result.content as Array<{ type: string; text: string }>)[0]?.text ?? '';
  assert.match(text, /design_core_writes_disabled/, 'the stable write-guard code must be present in the error text');
  assert.equal(fetchMock.mock.callCount(), 0, 'writes disabled must make zero network calls');
});

test('design_core_rollback is denied at the bridge with zero network calls when allowWrite:false', async () => {
  const fetchMock = mock.fn(() => {
    throw new Error('fetch should never be called when allowWrite is false');
  });
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  const result = await client.callTool({
    name: 'design_core_rollback',
    arguments: { site: 'blocked', entry_id: 'dc-entry-1', confirm: true },
  });
  assert.equal(result.isError, true, 'a denied write must be an MCP error result, not a success');
  const text = (result.content as Array<{ type: string; text: string }>)[0]?.text ?? '';
  assert.match(text, /design_core_writes_disabled/, 'the stable write-guard code must be present in the error text');
  assert.equal(fetchMock.mock.callCount(), 0, 'writes disabled must make zero network calls');
});

test('write guard denial text carries site/tool context without ever including the site token', async () => {
  const fetchMock = mock.fn(() => {
    throw new Error('fetch should never be called when allowWrite is false');
  });
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  const result = await client.callTool({
    name: 'design_core_publish_page',
    arguments: { site: 'blocked', page_id: 1, confirm: true },
  });
  const text = (result.content as Array<{ type: string; text: string }>)[0]?.text ?? '';
  assert.match(text, /site=blocked/);
  assert.match(text, /tool=design_core_publish_page/);
  assert.doesNotMatch(text, /dcmcp_test_secret_blocked/, 'the site credential must never appear in an error result');
  assert.equal(fetchMock.mock.callCount(), 0);
});
