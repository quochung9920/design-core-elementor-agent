/**
 * Site binding. Tool input takes only `site: "local" | "staging" | ...` -- never a raw
 * URL from the model -- so the bridge can never be pointed at an arbitrary WordPress
 * install. Sites are discovered from environment variables at startup:
 *
 *   DESIGN_CORE_SITE_<NAME>_URL          (required, e.g. http://wordpress)
 *   DESIGN_CORE_SITE_<NAME>_TOKEN        (required, a Design Core machine credential)
 *   DESIGN_CORE_SITE_<NAME>_ENVIRONMENT  (optional, default "local")
 *   DESIGN_CORE_SITE_<NAME>_ALLOW_WRITE  (optional, default "true")
 */
export interface SiteConfig {
  name: string;
  baseUrl: string;
  token: string;
  environment: string;
  allowWrite: boolean;
}

const SITE_VAR_PATTERN = /^DESIGN_CORE_SITE_([A-Z0-9_]+)_URL$/;

function loadSites(env: NodeJS.ProcessEnv): Record<string, SiteConfig> {
  const sites: Record<string, SiteConfig> = {};
  for (const key of Object.keys(env)) {
    const match = key.match(SITE_VAR_PATTERN);
    if (!match) continue;
    const upper = match[1] as string;
    const name = upper.toLowerCase();
    const baseUrl = env[key];
    const token = env[`DESIGN_CORE_SITE_${upper}_TOKEN`];
    if (!baseUrl || !token) {
      // eslint-disable-next-line no-console
      console.warn(`[design-core-mcp] site "${name}" is missing its TOKEN env var; skipping.`);
      continue;
    }
    sites[name] = {
      name,
      baseUrl: baseUrl.replace(/\/+$/, ''),
      token,
      environment: env[`DESIGN_CORE_SITE_${upper}_ENVIRONMENT`] || 'local',
      allowWrite: (env[`DESIGN_CORE_SITE_${upper}_ALLOW_WRITE`] ?? 'true').toLowerCase() !== 'false',
    };
  }
  return sites;
}

export function buildSiteRegistry(env: NodeJS.ProcessEnv = process.env): {
  sites: Record<string, SiteConfig>;
  names: string[];
} {
  const sites = loadSites(env);
  return { sites, names: Object.keys(sites) };
}

export class UnknownSiteError extends Error {
  constructor(site: string, known: string[]) {
    super(
      known.length
        ? `Unknown site "${site}". Configured sites: ${known.join(', ')}.`
        : `Unknown site "${site}". No sites are configured -- set DESIGN_CORE_SITE_<NAME>_URL/_TOKEN.`
    );
    this.name = 'UnknownSiteError';
  }
}

export function resolveSite(sites: Record<string, SiteConfig>, site: string): SiteConfig {
  const found = sites[site];
  if (!found) throw new UnknownSiteError(site, Object.keys(sites));
  return found;
}
