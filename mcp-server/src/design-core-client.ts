import type { SiteConfig } from './site-config.js';

/** Bounded request/response sizes -- never trust an unbounded body from either side. */
const MAX_REQUEST_BODY_BYTES = 2 * 1024 * 1024;
const MAX_RESPONSE_BODY_BYTES = 16 * 1024 * 1024;
const DEFAULT_TIMEOUT_MS = 30_000;

export class DesignCoreError extends Error {
  constructor(
    message: string,
    public readonly status?: number,
    public readonly code?: string,
    public readonly details?: unknown
  ) {
    super(message);
    this.name = 'DesignCoreError';
  }
}

export type DesignCoreMethod = 'GET' | 'POST';

async function fetchWithTimeout(url: string, init: RequestInit, timeoutMs: number, baseUrlForError: string): Promise<Response> {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), timeoutMs);
  try {
    return await fetch(url, { ...init, signal: controller.signal });
  } catch (error) {
    throw new DesignCoreError(`Could not reach Design Core at ${baseUrlForError}: ${(error as Error).message}`, undefined, 'design_core_mcp_unreachable');
  } finally {
    clearTimeout(timeout);
  }
}

/**
 * Thin, schema-agnostic REST v2 client. Contains no Design Core business logic --
 * it only sends a validated tool argument object as JSON and returns the parsed
 * response. All planning/mapping/persistence decisions happen server-side in WordPress.
 */
export async function callDesignCore(
  site: SiteConfig,
  method: DesignCoreMethod,
  path: string,
  body?: unknown,
  options: { timeoutMs?: number; idempotencyKey?: string } = {}
): Promise<unknown> {
  if (body !== undefined) {
    const encoded = JSON.stringify(body);
    if (encoded.length > MAX_REQUEST_BODY_BYTES) {
      throw new DesignCoreError('Request payload exceeds the 2 MB Design Core MCP limit.', 413, 'design_core_mcp_payload_too_large');
    }
  }

  const headers: Record<string, string> = {
    Authorization: `Bearer ${site.token}`,
    Accept: 'application/json',
  };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (options.idempotencyKey) headers['Idempotency-Key'] = options.idempotencyKey;
  const init: RequestInit = { method, headers, body: body !== undefined ? JSON.stringify(body) : undefined };
  const timeoutMs = options.timeoutMs ?? DEFAULT_TIMEOUT_MS;

  // Pretty permalinks (/wp-json/...) are the WordPress norm, but some installs run plain
  // permalinks where that 404s at the webserver before WordPress ever routes it -- fall
  // back to the always-available ?rest_route= form rather than requiring a specific
  // permalink structure just to talk to the bridge.
  let response = await fetchWithTimeout(`${site.baseUrl}/wp-json/design-core-elementor/v2${path}`, init, timeoutMs, site.baseUrl);
  if (response.status === 404) {
    const [routePath, query = ''] = path.split('?');
    const restRouteUrl = `${site.baseUrl}/?rest_route=${encodeURIComponent(`/design-core-elementor/v2${routePath}`)}${query ? `&${query}` : ''}`;
    response = await fetchWithTimeout(restRouteUrl, init, timeoutMs, site.baseUrl);
  }

  const text = await response.text();
  if (text.length > MAX_RESPONSE_BODY_BYTES) {
    throw new DesignCoreError('Design Core response exceeded the 16 MB Design Core MCP limit.', 502, 'design_core_mcp_response_too_large');
  }

  let data: unknown;
  try {
    data = text ? JSON.parse(text) : undefined;
  } catch {
    data = undefined;
  }

  if (!response.ok) {
    const parsed = data as { message?: string; code?: string; data?: unknown } | undefined;
    throw new DesignCoreError(
      parsed?.message ?? `Design Core request failed with HTTP ${response.status}.`,
      response.status,
      parsed?.code,
      parsed?.data
    );
  }
  return data;
}
