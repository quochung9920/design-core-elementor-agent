import { test, before, after, mock } from 'node:test';
import assert from 'node:assert/strict';
import type { Server } from 'node:http';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';
import { createApp } from '../src/server.js';
import type { BridgeContext } from '../src/context.js';

/**
 * Real HTTP MCP transport tests: a genuine Express server bound to 127.0.0.1 on an OS-assigned
 * ephemeral port, exercised through the SDK's real StreamableHTTPClientTransport -- never
 * InMemoryTransport. globalThis.fetch is mocked only at the same outbound boundary
 * tools.test.ts already mocks (the server's own call to Design Core/WordPress); the test
 * driver's own requests to this local server, and the SDK client transport's own networking,
 * always use `realFetch` (captured before any mocking) explicitly -- client and server run in
 * the same process here, so leaving them on the shared global would make a Design-Core mock
 * also swallow the client's own request to this test server. The inbound HTTP transport,
 * Express middleware chain (rate limit -> auth -> JSON body parsing -> MCP handler), and MCP
 * protocol negotiation are all real regardless.
 */

const realFetch = globalThis.fetch;

const REAL_TOKEN = 'test-inbound-token-' + 'x'.repeat(40);
const WP_TOKEN = 'dcmcp_test_wordpress_machine_credential_secret_do_not_leak';

const ctx: BridgeContext = {
  siteNames: ['local'],
  sites: { local: { name: 'local', baseUrl: 'http://wordpress', token: WP_TOKEN, environment: 'local', allowWrite: true } },
};

let server: Server;
let baseUrl: URL;

before(async () => {
  const app = createApp(ctx, { mode: 'token', token: REAL_TOKEN });
  await new Promise<void>((resolve) => {
    server = app.listen(0, '127.0.0.1', () => resolve());
  });
  const address = server.address();
  if (!address || typeof address === 'string') throw new Error('expected a real ephemeral port');
  baseUrl = new URL(`http://127.0.0.1:${address.port}/mcp`);
});

after(async () => {
  globalThis.fetch = realFetch;
  await new Promise<void>((resolve) => server.close(() => resolve()));
});

/** The SDK client's own transport always uses realFetch, regardless of any Design-Core mock
 *  installed on globalThis.fetch for the server side of this same process. */
function connectClient(headers?: Record<string, string>) {
  const transport = new StreamableHTTPClientTransport(baseUrl, { fetch: realFetch, ...(headers ? { requestInit: { headers } } : {}) });
  const client = new Client({ name: 'http-auth-test-client', version: '0.0.0' });
  return { client, transport };
}

const initializeBody = JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'initialize', params: {} });
const jsonHeaders = { 'content-type': 'application/json', accept: 'application/json, text/event-stream' };

test('no Authorization header is rejected with 401, WWW-Authenticate: Bearer, and zero MCP/network activity', async () => {
  const fetchMock = mock.fn(() => {
    throw new Error('fetch must never be called for an unauthenticated request');
  });
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  const res = await realFetch(baseUrl, { method: 'POST', headers: jsonHeaders, body: initializeBody });
  assert.equal(res.status, 401);
  assert.equal(res.headers.get('www-authenticate'), 'Bearer');
  const body = (await res.json()) as { error: string };
  assert.equal(body.error, 'unauthorized');
  assert.equal(fetchMock.mock.callCount(), 0);
  globalThis.fetch = realFetch;
});

test('a malformed Authorization header is rejected with 401', async () => {
  for (const badHeader of ['Bearer', 'Basic dXNlcjpwYXNz', REAL_TOKEN, `Bearer${REAL_TOKEN}`]) {
    const res = await realFetch(baseUrl, { method: 'POST', headers: { ...jsonHeaders, authorization: badHeader }, body: initializeBody });
    assert.equal(res.status, 401, `expected 401 for malformed header ${JSON.stringify(badHeader)}`);
  }
});

test('the wrong token is rejected with 401 and never reaches a tool handler', async () => {
  const fetchMock = mock.fn(() => {
    throw new Error('fetch must never be called for a wrong-token request');
  });
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  const res = await realFetch(baseUrl, {
    method: 'POST',
    headers: { ...jsonHeaders, authorization: 'Bearer wrong-token-entirely' },
    body: initializeBody,
  });
  assert.equal(res.status, 401);
  assert.equal(fetchMock.mock.callCount(), 0);
  globalThis.fetch = realFetch;
});

test('a token differing only in length from the real one is rejected (exercises the length-mismatch branch)', async () => {
  const res = await realFetch(baseUrl, {
    method: 'POST',
    headers: { ...jsonHeaders, authorization: `Bearer ${REAL_TOKEN}extra` },
    body: initializeBody,
  });
  assert.equal(res.status, 401);
});

test('the correct token reaches the real MCP transport: initialize + listTools succeed over real HTTP', async () => {
  const { client, transport } = connectClient({ Authorization: `Bearer ${REAL_TOKEN}` });
  await client.connect(transport);
  const { tools } = await client.listTools();
  assert.equal(tools.length, 22, 'all twenty-two tools are reachable through the real authenticated HTTP transport');
  await client.close();
});

test('a real tool call over the authenticated HTTP transport reaches the (mocked) Design Core call with the WordPress token, and neither token leaks in the response', async () => {
  const fetchMock = mock.fn(async (url: string, init?: RequestInit) => {
    assert.equal(url, 'http://wordpress/wp-json/design-core-elementor/v2/site/status');
    assert.equal((init?.headers as Record<string, string>).Authorization, `Bearer ${WP_TOKEN}`);
    return new Response(JSON.stringify({ plugin_version: '1.0.0-rc21', write_enabled: true }), { status: 200 });
  });
  globalThis.fetch = fetchMock as unknown as typeof fetch;
  const { client, transport } = connectClient({ Authorization: `Bearer ${REAL_TOKEN}` });
  await client.connect(transport);
  const result = await client.callTool({ name: 'design_core_site_status', arguments: { site: 'local' } });
  const text = (result.content as Array<{ type: string; text: string }>)[0]?.text ?? '';
  assert.doesNotMatch(text, new RegExp(WP_TOKEN.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), 'the WordPress machine credential must never appear in a tool result');
  assert.doesNotMatch(text, new RegExp(REAL_TOKEN.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), 'the inbound MCP token must never appear in a tool result');
  assert.equal(fetchMock.mock.callCount(), 1);
  await client.close();
  globalThis.fetch = realFetch;
});

test('rate limiting: excess requests from one source receive 429 with Retry-After, before either token check matters', async () => {
  // A dedicated app + server with a tiny limit, isolated from every other test's request count.
  const app = createApp(ctx, { mode: 'token', token: REAL_TOKEN }, { windowMs: 60_000, limit: 2 });
  const limited = await new Promise<Server>((resolve) => {
    const s = app.listen(0, '127.0.0.1', () => resolve(s));
  });
  try {
    const address = limited.address();
    if (!address || typeof address === 'string') throw new Error('expected a real ephemeral port');
    const url = `http://127.0.0.1:${address.port}/mcp`;
    const request = () => realFetch(url, { method: 'POST', headers: jsonHeaders, body: initializeBody });
    const first = await request();
    const second = await request();
    const third = await request();
    assert.equal(first.status, 401, 'the first two (unauthenticated, but within budget) requests are not rate-limited');
    assert.equal(second.status, 401);
    assert.equal(third.status, 429, 'the third request within the window exceeds the limit of 2');
    assert.ok(third.headers.get('retry-after'), 'a 429 response includes Retry-After');
  } finally {
    await new Promise<void>((resolve) => limited.close(() => resolve()));
  }
});

test('the public /healthz endpoint reveals no internal topology, tokens, or site details', async () => {
  const res = await realFetch(new URL('/healthz', baseUrl));
  assert.equal(res.status, 200);
  const body = (await res.json()) as Record<string, unknown>;
  assert.deepEqual(body, { status: 'ok' });
  const raw = JSON.stringify(body);
  assert.doesNotMatch(raw, /wordpress|token|site|local/i);
});

test('/healthz requires no authentication (safe to probe without a token)', async () => {
  const res = await realFetch(new URL('/healthz', baseUrl));
  assert.equal(res.status, 200);
});

test('the detailed /health endpoint is auth-protected', async () => {
  const res = await realFetch(new URL('/health', baseUrl));
  assert.equal(res.status, 401);
});

test('MCP_INBOUND_AUTH_MODE=none is honored for explicit local-only development (no auth applied)', async () => {
  const app = createApp(ctx, { mode: 'none' });
  const s = await new Promise<Server>((resolve) => {
    const srv = app.listen(0, '127.0.0.1', () => resolve(srv));
  });
  try {
    const address = s.address();
    if (!address || typeof address === 'string') throw new Error('expected a real ephemeral port');
    const res = await realFetch(`http://127.0.0.1:${address.port}/mcp`, { method: 'POST', headers: jsonHeaders, body: initializeBody });
    assert.notEqual(res.status, 401, 'mode:none must not reject an unauthenticated request');
  } finally {
    await new Promise<void>((resolve) => s.close(() => resolve()));
  }
});
