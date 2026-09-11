import { timingSafeEqual } from 'node:crypto';
import type { NextFunction, Request, Response } from 'express';

/**
 * Inbound authentication for the public /mcp endpoint -- separate from, and never derived
 * from, the outbound Design Core WordPress machine credential (site.token in site-config.ts).
 * That credential authenticates the bridge TO WordPress; this authenticates a caller TO the
 * bridge. Reusing one for the other would mean anyone who can call the MCP endpoint could
 * infer or replay the WordPress credential, and vice versa -- they must stay independent.
 */
export type InboundAuthMode = 'token' | 'none';

export interface InboundAuthConfig {
  mode: InboundAuthMode;
  /** Only present when mode === 'token'. */
  token?: string;
}

export class InboundAuthConfigError extends Error {}

/**
 * The exact, small set of hostnames this process trusts as provably loopback-only. A literal
 * allowlist, not a DNS/getaddrinfo resolution check -- whether some other hostname currently
 * resolves to 127.0.0.1 depends on /etc/hosts state that can change later without this code
 * changing, so anything not spelled exactly one of these three ways is treated as non-loopback.
 */
const LOOPBACK_HOSTS = new Set(['127.0.0.1', '::1', 'localhost']);

function isLoopbackHost(host: string): boolean {
  return LOOPBACK_HOSTS.has(host.trim().toLowerCase());
}

/**
 * Reads and validates inbound auth configuration from the environment. Fails closed: mode
 * defaults to "token", and token mode with an absent/empty MCP_INBOUND_TOKEN throws rather
 * than silently falling back to unauthenticated access. Call this once at startup, before
 * the HTTP server binds to any port -- a thrown InboundAuthConfigError should abort startup.
 *
 * "none" mode is deliberately hard to reach by accident: a developer must not be able to make
 * an Internet-reachable server unauthenticated by flipping a single env var. It requires BOTH
 * an explicit second opt-in (MCP_ALLOW_INSECURE_LOCAL_AUTH=1) AND that this same env carries a
 * HOST value this process can prove is loopback-only (127.0.0.1 / ::1 / localhost) -- the exact
 * value main() will actually bind to, read from the same env object, so there is no way for the
 * validator to approve a HOST the server then binds differently. The Docker/public topology
 * always sets HOST=0.0.0.0, so "none" mode can never start there regardless of any other setting;
 * the server does not trust an external NAT/firewall to make that safe on its behalf.
 */
export function loadInboundAuthConfig(env: NodeJS.ProcessEnv = process.env): InboundAuthConfig {
  const rawMode = (env.MCP_INBOUND_AUTH_MODE ?? 'token').trim().toLowerCase();
  if (rawMode === 'none') {
    if ((env.MCP_ALLOW_INSECURE_LOCAL_AUTH ?? '').trim() !== '1') {
      throw new InboundAuthConfigError(
        'MCP_INBOUND_AUTH_MODE=none also requires MCP_ALLOW_INSECURE_LOCAL_AUTH=1 as an explicit second opt-in. ' +
          'Refusing to start an unauthenticated MCP endpoint.'
      );
    }
    const host = (env.HOST ?? '127.0.0.1').trim();
    if (!isLoopbackHost(host)) {
      throw new InboundAuthConfigError(
        'MCP_INBOUND_AUTH_MODE=none may only run when the server binds a loopback host ' +
          `(127.0.0.1, ::1, or localhost). HOST=${JSON.stringify(host)} is not recognized as loopback -- ` +
          'refusing to start an unauthenticated MCP endpoint that could be reachable beyond this machine.'
      );
    }
    return { mode: 'none' };
  }
  if (rawMode !== 'token') {
    throw new InboundAuthConfigError(`Unknown MCP_INBOUND_AUTH_MODE "${rawMode}". Supported values are "token" or "none".`);
  }
  const token = env.MCP_INBOUND_TOKEN;
  if (!token || token.trim() === '') {
    throw new InboundAuthConfigError(
      'MCP_INBOUND_AUTH_MODE=token requires a non-empty MCP_INBOUND_TOKEN. Refusing to start rather than fall back to unauthenticated access. ' +
        'Generate one with: openssl rand -base64 48'
    );
  }
  return { mode: 'token', token };
}

/**
 * Constant-time string comparison. crypto.timingSafeEqual throws on a length mismatch, and
 * an early `return false` for that case would itself leak length via timing -- so an
 * unequal-length comparison still performs a same-cost dummy compare before reporting false.
 */
function timingSafeStringEqual(presented: string, expected: string): boolean {
  const presentedBuf = Buffer.from(presented, 'utf8');
  const expectedBuf = Buffer.from(expected, 'utf8');
  if (presentedBuf.length !== expectedBuf.length) {
    timingSafeEqual(presentedBuf, presentedBuf);
    return false;
  }
  return timingSafeEqual(presentedBuf, expectedBuf);
}

function denyUnauthorized(res: Response): void {
  res.set('WWW-Authenticate', 'Bearer');
  // A plain HTTP 401 here, never an HTTP 200 MCP-shaped error result -- an unauthenticated
  // caller must not be able to distinguish "wrong token" from "reached the MCP protocol".
  res.status(401).json({ error: 'unauthorized', message: 'A valid Authorization: Bearer token is required.' });
}

/**
 * Express middleware enforcing inbound Bearer auth. Mount this (and the rate limiter) BEFORE
 * express.json() body parsing and before the /mcp route handler that creates an McpServer --
 * a rejected request never parses an untrusted body, never constructs an MCP server, never
 * runs a tool handler, and never reaches WordPress.
 */
export function requireInboundAuth(config: InboundAuthConfig) {
  return (req: Request, res: Response, next: NextFunction): void => {
    if (config.mode === 'none') {
      next();
      return;
    }
    const header = req.get('authorization') ?? '';
    const match = /^Bearer\s+(.+)$/i.exec(header);
    if (!match) {
      denyUnauthorized(res);
      return;
    }
    const presented = match[1] as string;
    if (!timingSafeStringEqual(presented, config.token as string)) {
      denyUnauthorized(res);
      return;
    }
    next();
  };
}
