import express from 'express';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import { buildSiteRegistry } from './site-config.js';
import type { BridgeContext } from './context.js';
import { buildHealthReport } from './health.js';
import { loadInboundAuthConfig, requireInboundAuth, InboundAuthConfigError, type InboundAuthConfig } from './inbound-auth.js';
import { createMcpRateLimiter } from './rate-limit.js';
import { registerSiteStatusTool } from './tools/site-status.js';
import { registerPageSnapshotTool } from './tools/page-snapshot.js';
import { registerPreviewBuildTool } from './tools/preview-build.js';
import { registerPreviewFigmaTool } from './tools/preview-figma.js';
import { registerRecommendDesignSystemTool } from './tools/recommend-design-system.js';
import { registerPreviewDesignSystemTool } from './tools/preview-design-system.js';
import { registerUpdatePageTool } from './tools/update-page.js';
import { registerVisualFeedbackTool } from './tools/visual-feedback.js';
import { registerAutoCorrectTool } from './tools/auto-correct.js';
import { registerHistoryTool } from './tools/history.js';
import { registerRollbackTool } from './tools/rollback.js';
import { registerPublishPageTool } from './tools/publish-page.js';
import { registerSiteIntelligenceTools } from './tools/site-intelligence.js';

const PKG_VERSION = process.env.npm_package_version ?? '1.0.0-rc21';

function createMcpServer(ctx: BridgeContext): McpServer {
  const server = new McpServer({ name: 'design-core-mcp', version: PKG_VERSION });
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
  registerSiteIntelligenceTools(server, ctx);
  return server;
}

export function createApp(
  ctx: BridgeContext,
  authConfig: InboundAuthConfig,
  rateLimitOptions?: { windowMs?: number; limit?: number }
) {
  const app = express();
  app.disable('x-powered-by');

  // Scoped to exactly the known reverse-proxy topology (deploy/caddy/Caddyfile, on the same
  // private Docker network, is the only thing ever allowed to reach this container -- see
  // docker-compose.yml, port 3000 is never published to 0.0.0.0). Trusting "1" hop means
  // req.ip is read from the single X-Forwarded-For entry Caddy itself sets, not from an
  // arbitrary caller-supplied header -- NEVER `app.set('trust proxy', true)`, which would let
  // any caller spoof its own source IP and defeat the rate limiter below.
  app.set('trust proxy', 1);

  const rateLimiter = createMcpRateLimiter(rateLimitOptions?.windowMs, rateLimitOptions?.limit);
  const auth = requireInboundAuth(authConfig);

  // Rate limiting runs before auth so a flood of *wrong* tokens is bounded by the same policy
  // as a flood of valid ones -- auth rejecting first would let an attacker skip the limiter
  // entirely by never presenting a valid token. Body parsing (which does real work on
  // attacker-controlled input) runs last, after both gates, and only for /mcp.
  //
  // Stateless MCP endpoint: a fresh McpServer + transport pair per request, matching the
  // SDK's documented stateless pattern (sessionIdGenerator: undefined). This bridge holds
  // no per-connection state of its own -- every tool call is an independent, schema-validated
  // pass-through to Design Core.
  app.post('/mcp', rateLimiter, auth, express.json({ limit: '2mb' }), async (req, res) => {
    try {
      const server = createMcpServer(ctx);
      const transport = new StreamableHTTPServerTransport({ sessionIdGenerator: undefined });
      res.on('close', () => {
        transport.close();
        server.close();
      });
      await server.connect(transport);
      await transport.handleRequest(req, res, req.body);
    } catch (error) {
      // Never forward the caught error's message/stack to the client -- it could contain
      // request internals; log server-side only, exactly as before this hardening pass.
      // eslint-disable-next-line no-console
      console.error('[design-core-mcp] request failed:', error);
      if (!res.headersSent) {
        res.status(500).json({ jsonrpc: '2.0', error: { code: -32603, message: 'Internal MCP bridge error' }, id: null });
      }
    }
  });

  // Minimal public health check: no site URLs, no token presence/absence, no hostnames, no
  // per-site reachability. Intentionally unauthenticated (load balancers/uptime probes expect
  // that) since it reveals nothing beyond "the process is up".
  app.get('/healthz', (_req, res) => {
    res.status(200).json({ status: 'ok' });
  });

  // Detailed, per-site reachability report -- auth-protected in addition to never being routed
  // publicly by the reverse proxy (deploy/caddy/Caddyfile only forwards /mcp and /healthz).
  app.get('/health', auth, async (_req, res) => {
    const report = await buildHealthReport(ctx.sites);
    const healthy = report.sites.every((s) => s.wordpress_reachable && s.design_core_api_reachable);
    res.status(healthy || report.sites.length === 0 ? 200 : 503).json(report);
  });

  return app;
}

function main() {
  let authConfig: InboundAuthConfig;
  try {
    authConfig = loadInboundAuthConfig();
  } catch (error) {
    // Fail closed: never start (and never bind a port) without a valid inbound auth
    // configuration. The error message here is a fixed, static string describing what's
    // missing -- it never contains a token value even when one was rejected for some other
    // reason, because loadInboundAuthConfig() only ever throws before reading any secret into
    // a variable that could be interpolated.
    // eslint-disable-next-line no-console
    console.error(`[design-core-mcp] Refusing to start: ${error instanceof InboundAuthConfigError ? error.message : String(error)}`);
    process.exit(1);
  }
  if (authConfig.mode === 'none') {
    // eslint-disable-next-line no-console
    console.warn('[design-core-mcp] MCP_INBOUND_AUTH_MODE=none: the /mcp endpoint is UNAUTHENTICATED. This is only safe for local-only development that is never reachable from outside this machine.');
  }

  const { sites, names } = buildSiteRegistry();
  if (names.length === 0) {
    // eslint-disable-next-line no-console
    console.warn('[design-core-mcp] No sites configured. Set DESIGN_CORE_SITE_<NAME>_URL / _TOKEN before connecting a client.');
  } else {
    // eslint-disable-next-line no-console
    console.log(`[design-core-mcp] Configured sites: ${names.join(', ')}`);
  }

  const app = createApp({ sites, siteNames: names }, authConfig);
  const port = Number(process.env.PORT ?? 3000);
  const host = process.env.HOST ?? '127.0.0.1';
  const server = app.listen(port, host, () => {
    // eslint-disable-next-line no-console
    console.log(`[design-core-mcp] Listening on http://${host}:${port} (MCP: POST /mcp, health: GET /healthz)`);
  });
  // Bounded connection lifetimes: never let a slow/stalled client or proxy hop hold a socket
  // open indefinitely. headersTimeout must exceed requestTimeout per Node's own documented
  // constraint (otherwise it fires first and every request would time out at the header stage).
  server.requestTimeout = 30_000;
  server.headersTimeout = 35_000;
  server.keepAliveTimeout = 5_000;
}

const isMainModule = process.argv[1] && import.meta.url === `file://${process.argv[1]}`;
if (isMainModule) main();
