import { z } from 'zod';
import type { SiteConfig } from './site-config.js';

export interface BridgeContext {
  sites: Record<string, SiteConfig>;
  siteNames: string[];
}

/** `z.enum` requires a non-empty tuple; fall back to a plain string when no site is configured yet (resolveSite() still throws a clear error at call time). */
export function siteField(names: string[]): z.ZodType<string> {
  if (names.length === 0) return z.string().min(1).describe('Configured site key (no sites are currently configured on this bridge).');
  return z.enum(names as [string, ...string[]]).describe('Which configured Design Core site to target. Never a raw URL.');
}
