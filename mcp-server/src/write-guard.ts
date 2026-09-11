/**
 * MCP Bridge Write Guard
 *
 * Enforces write/destructive operation policies at the bridge level,
 * BEFORE network requests are made to WordPress.
 *
 * P2 POLICY: allowWrite enforcement
 * - Site configuration has allowWrite boolean flag
 * - All MCP write/destructive tools must check this BEFORE calling WordPress
 * - If allowWrite=false, reject with error code before any network I/O
 *
 * P3 POLICY: Credential environment binding (checked in WordPress REST controller)
 * - Machine credentials have environment metadata
 * - WordPress Remote Settings have environment (local/staging/production)
 * - Design Core REST v2 validates environment match AFTER credential validation
 * - Error code: design_core_credential_environment_mismatch
 */

import type { SiteConfig } from './site-config.js';

export class WriteGuardError extends Error {
  constructor(
    public readonly code: string,
    public readonly details: Record<string, unknown> = {}
  ) {
    super(`[WriteGuard] ${code}`);
    this.name = 'WriteGuardError';
  }
}

/**
 * Assert that write operations are enabled for this site.
 * Called BEFORE any MCP write/destructive tool makes a network request to WordPress.
 *
 * P2: ALLOW_WRITE enforcement
 *
 * @param site Site configuration
 * @param toolName Name of the tool being guarded (for error messages)
 * @throws WriteGuardError with code 'design_core_writes_disabled' if writes are disabled
 */
export function assertSiteWriteEnabled(site: SiteConfig, toolName: string): void {
  if (!site.allowWrite) {
    throw new WriteGuardError('design_core_writes_disabled', {
      site: site.name,
      tool: toolName,
      message: `Remote write operations are disabled for site "${site.name}". Set DESIGN_CORE_SITE_${site.name.toUpperCase()}_ALLOW_WRITE=true to enable writes.`,
    });
  }
}
