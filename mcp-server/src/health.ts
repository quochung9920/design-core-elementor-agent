import type { SiteConfig } from './site-config.js';
import { callDesignCore, DesignCoreError } from './design-core-client.js';

export interface SiteHealth {
  site: string;
  wordpress_reachable: boolean;
  design_core_api_reachable: boolean;
  error?: string;
}

/**
 * Cheap reachability checks only -- GET /wp-json/ (WordPress's own REST index, no plugin
 * required) and GET /site/status (compact by design). Deliberately never calls
 * page_snapshot or any other potentially expensive Design Core route here.
 */
async function checkSite(site: SiteConfig): Promise<SiteHealth> {
  const result: SiteHealth = { site: site.name, wordpress_reachable: false, design_core_api_reachable: false };
  try {
    let res = await fetch(`${site.baseUrl}/wp-json/`, { signal: AbortSignal.timeout(5000) });
    if (res.status === 404) res = await fetch(`${site.baseUrl}/?rest_route=/`, { signal: AbortSignal.timeout(5000) });
    result.wordpress_reachable = res.ok;
  } catch {
    result.wordpress_reachable = false;
  }
  try {
    await callDesignCore(site, 'GET', '/site/status', undefined, { timeoutMs: 5000 });
    result.design_core_api_reachable = true;
  } catch (error) {
    result.design_core_api_reachable = false;
    result.error = error instanceof DesignCoreError ? error.message : String(error);
  }
  return result;
}

export async function buildHealthReport(sites: Record<string, SiteConfig>) {
  const entries = await Promise.all(Object.values(sites).map(checkSite));
  return {
    bridge: 'healthy',
    version: process.env.npm_package_version ?? 'dev',
    sites: entries,
  };
}
