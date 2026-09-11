import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildSiteRegistry, resolveSite, UnknownSiteError } from '../src/site-config.js';

test('discovers sites from DESIGN_CORE_SITE_<NAME>_URL/_TOKEN env pairs', () => {
  const { sites, names } = buildSiteRegistry({
    DESIGN_CORE_SITE_LOCAL_URL: 'http://wordpress/',
    DESIGN_CORE_SITE_LOCAL_TOKEN: 'dcmcp_abc_def',
    DESIGN_CORE_SITE_LOCAL_ENVIRONMENT: 'local',
  } as NodeJS.ProcessEnv);
  assert.deepEqual(names, ['local']);
  assert.equal(sites.local?.baseUrl, 'http://wordpress'); // trailing slash stripped
  assert.equal(sites.local?.token, 'dcmcp_abc_def');
  assert.equal(sites.local?.allowWrite, true);
});

test('skips a site missing its TOKEN env var instead of registering a broken one', () => {
  const { names } = buildSiteRegistry({ DESIGN_CORE_SITE_STAGING_URL: 'http://staging.example' } as NodeJS.ProcessEnv);
  assert.deepEqual(names, []);
});

test('DESIGN_CORE_SITE_<NAME>_ALLOW_WRITE=false disables writes for that site', () => {
  const { sites } = buildSiteRegistry({
    DESIGN_CORE_SITE_PROD_URL: 'https://prod.example',
    DESIGN_CORE_SITE_PROD_TOKEN: 'dcmcp_x_y',
    DESIGN_CORE_SITE_PROD_ALLOW_WRITE: 'false',
  } as NodeJS.ProcessEnv);
  assert.equal(sites.prod?.allowWrite, false);
});

test('resolveSite rejects a site name that was never configured (no arbitrary URL targeting)', () => {
  const { sites } = buildSiteRegistry({
    DESIGN_CORE_SITE_LOCAL_URL: 'http://wordpress',
    DESIGN_CORE_SITE_LOCAL_TOKEN: 'dcmcp_abc_def',
  } as NodeJS.ProcessEnv);
  assert.throws(() => resolveSite(sites, 'http://evil.example'), UnknownSiteError);
  assert.throws(() => resolveSite(sites, 'production'), UnknownSiteError);
});
