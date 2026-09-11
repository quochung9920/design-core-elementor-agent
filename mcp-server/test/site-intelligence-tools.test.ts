import { test, before, after, mock } from 'node:test';
import assert from 'node:assert/strict';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { BridgeContext } from '../src/context.js';
import { registerSiteIntelligenceTools, SITE_INTELLIGENCE_TOOL_NAMES } from '../src/tools/site-intelligence.js';

const READ_TOOLS = SITE_INTELLIGENCE_TOOL_NAMES.filter((name) => name !== 'design_core_create_page');
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
  const server = new McpServer({ name: 'site-intelligence-test', version: '0.0.0' });
  registerSiteIntelligenceTools(server, ctx);
  const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();
  client = new Client({ name: 'test-client', version: '0.0.0' });
  await Promise.all([client.connect(clientTransport), server.connect(serverTransport)]);
});

after(() => { globalThis.fetch = originalFetch; });

test('exposes exactly the ten Site Intelligence v1 tools', async () => {
  const { tools } = await client.listTools();
  assert.deepEqual(tools.map((tool) => tool.name).sort(), [...SITE_INTELLIGENCE_TOOL_NAMES].sort());
});

test('the nine discovery/intelligence tools are read-only and non-destructive', async () => {
  const { tools } = await client.listTools();
  for (const name of READ_TOOLS) {
    const tool = tools.find((candidate) => candidate.name === name);
    assert.ok(tool, `${name} must be registered`);
    assert.equal(tool.annotations?.readOnlyHint, true);
    assert.notEqual(tool.annotations?.destructiveHint, true);
  }
});

test('create_page is a confirmation-gated, idempotent write tool', async () => {
  const { tools } = await client.listTools();
  const tool = tools.find((candidate) => candidate.name === 'design_core_create_page');
  assert.ok(tool);
  assert.equal(tool.annotations?.readOnlyHint, false);
  assert.equal(tool.annotations?.destructiveHint, false);
  assert.equal(tool.annotations?.idempotentHint, true);
  const confirm = (tool.inputSchema as { properties?: Record<string, unknown> }).properties?.confirm;
  assert.ok(confirm, 'create_page must declare confirm:true in its input schema');
});

test('site_map reaches only the configured Design Core site with the machine Bearer token', async () => {
  const fetchMock = mock.fn(async (url: string, init?: RequestInit) => {
    assert.equal(url, 'http://wordpress/wp-json/design-core-elementor/v2/site/map?limit=25');
    assert.equal((init?.headers as Record<string, string>).Authorization, 'Bearer dcmcp_test_secret');
    return new Response(JSON.stringify({ pages: [{ id: 7, title: 'Services' }] }), { status: 200 });
  });
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  const result = await client.callTool({ name: 'design_core_site_map', arguments: { site: 'local', limit: 25 } });
  assert.equal(result.isError, undefined);
  assert.equal(fetchMock.mock.callCount(), 1);
});

test('widget_search passes structured requirements to the runtime intelligence endpoint', async () => {
  const fetchMock = mock.fn(async (url: string, init?: RequestInit) => {
    assert.equal(url, 'http://wordpress/wp-json/design-core-elementor/v2/elementor/widgets/search');
    const body = JSON.parse(String(init?.body ?? '{}')) as { intent?: string; capabilities?: string[]; query?: string };
    assert.equal(body.intent, 'dynamic-content');
    assert.deepEqual(body.capabilities, ['dynamic', 'repeater']);
    assert.match(body.query ?? '', /team/i);
    return new Response(JSON.stringify({ candidates: [{ name: 'loop-grid', score: 1 }] }), { status: 200 });
  });
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  const result = await client.callTool({
    name: 'design_core_widget_search',
    arguments: { site: 'local', query: 'team listing from dynamic content', intent: 'dynamic-content', capabilities: ['dynamic', 'repeater'] },
  });
  assert.equal(result.isError, undefined);
  assert.equal(fetchMock.mock.callCount(), 1);
});

test('widget_schema uses a bounded GET and can opt into detail', async () => {
  const fetchMock = mock.fn(async (url: string) => {
    assert.equal(url, 'http://wordpress/wp-json/design-core-elementor/v2/elementor/widgets/loop-grid?detail=true');
    return new Response(JSON.stringify({ name: 'loop-grid', control_count: 42 }), { status: 200 });
  });
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  const result = await client.callTool({ name: 'design_core_widget_schema', arguments: { site: 'local', widget: 'loop-grid', detail: true } });
  assert.equal(result.isError, undefined);
  assert.equal(fetchMock.mock.callCount(), 1);
});

test('create_page without confirm:true is rejected by MCP schema validation before network I/O', async () => {
  const fetchMock = mock.fn(() => { throw new Error('fetch must not run when confirmation is missing'); });
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  const result = await client.callTool({ name: 'design_core_create_page', arguments: { site: 'local', title: 'Disposable draft' } });
  assert.equal(result.isError, true);
  assert.equal(fetchMock.mock.callCount(), 0);
});

test('create_page is denied at the bridge with zero network calls when allowWrite:false', async () => {
  const fetchMock = mock.fn(() => { throw new Error('fetch must not run when bridge writes are disabled'); });
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  const result = await client.callTool({ name: 'design_core_create_page', arguments: { site: 'blocked', title: 'Should not exist', confirm: true } });
  assert.equal(result.isError, true);
  assert.equal(fetchMock.mock.callCount(), 0);
});

test('create_page with confirmation sends an Idempotency-Key to the draft-only route', async () => {
  const fetchMock = mock.fn(async (url: string, init?: RequestInit) => {
    assert.equal(url, 'http://wordpress/wp-json/design-core-elementor/v2/pages/create');
    const headers = init?.headers as Record<string, string>;
    assert.ok(headers['Idempotency-Key']);
    const body = JSON.parse(String(init?.body ?? '{}')) as { title?: string; confirm?: boolean };
    assert.equal(body.title, 'New BIM Service');
    assert.equal(body.confirm, true);
    return new Response(JSON.stringify({ status: 'success', page_id: 99, post_status: 'draft' }), { status: 200 });
  });
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  const result = await client.callTool({ name: 'design_core_create_page', arguments: { site: 'local', title: 'New BIM Service', confirm: true } });
  assert.equal(result.isError, undefined);
  assert.equal(fetchMock.mock.callCount(), 1);
});
