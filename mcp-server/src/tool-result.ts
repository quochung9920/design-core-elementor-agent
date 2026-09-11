import { DesignCoreError } from './design-core-client.js';
import { UnknownSiteError } from './site-config.js';
import { WriteGuardError } from './write-guard.js';

export interface McpToolResult {
  [key: string]: unknown;
  content: Array<{ type: 'text'; text: string }>;
  isError?: boolean;
}

export function jsonResult(value: unknown): McpToolResult {
  return { content: [{ type: 'text', text: JSON.stringify(value, null, 2) }] };
}

/** Only these WriteGuardError.details keys are ever echoed back to the caller -- never a full
 *  arbitrary details dump, so a future WriteGuardError call site can't accidentally leak
 *  something sensitive through this generic error path. */
const SAFE_WRITE_GUARD_DETAIL_KEYS = ['message', 'site', 'tool', 'environment'] as const;

export function errorResult(error: unknown): McpToolResult {
  if (error instanceof WriteGuardError) {
    const details = error.details ?? {};
    const parts = [`code=${error.code}`];
    for (const key of SAFE_WRITE_GUARD_DETAIL_KEYS) {
      const value = details[key];
      if (typeof value === 'string' && value) parts.push(key === 'message' ? value : `${key}=${value}`);
    }
    return { isError: true, content: [{ type: 'text', text: `Write guard denied the request: ${parts.join(' ')}` }] };
  }
  if (error instanceof UnknownSiteError) {
    return { isError: true, content: [{ type: 'text', text: `Site binding error: ${error.message}` }] };
  }
  if (error instanceof DesignCoreError) {
    const parts = [`Design Core error${error.status ? ` (HTTP ${error.status})` : ''}: ${error.message}`];
    if (error.code) parts.push(`code=${error.code}`);
    return { isError: true, content: [{ type: 'text', text: parts.join(' ') }] };
  }
  const message = error instanceof Error ? error.message : String(error);
  return { isError: true, content: [{ type: 'text', text: `Unexpected MCP bridge error: ${message}` }] };
}

/** Wraps a tool handler so every thrown error becomes a proper MCP error result instead of crashing the request. */
export function guarded(handler: () => Promise<McpToolResult>): Promise<McpToolResult> {
  return handler().catch(errorResult);
}
