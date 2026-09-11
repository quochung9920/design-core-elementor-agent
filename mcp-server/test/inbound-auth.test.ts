import { test } from 'node:test';
import assert from 'node:assert/strict';
import { loadInboundAuthConfig, InboundAuthConfigError } from '../src/inbound-auth.js';

test('defaults to token mode, and fails closed when no token is configured', () => {
  assert.throws(() => loadInboundAuthConfig({} as NodeJS.ProcessEnv), InboundAuthConfigError);
});

test('token mode with an empty-string token still fails closed (never treated as "no token = open")', () => {
  assert.throws(
    () => loadInboundAuthConfig({ MCP_INBOUND_AUTH_MODE: 'token', MCP_INBOUND_TOKEN: '   ' } as NodeJS.ProcessEnv),
    InboundAuthConfigError
  );
});

test('token mode with a real token loads successfully', () => {
  const config = loadInboundAuthConfig({ MCP_INBOUND_AUTH_MODE: 'token', MCP_INBOUND_TOKEN: 'a-real-secret' } as NodeJS.ProcessEnv);
  assert.deepEqual(config, { mode: 'token', token: 'a-real-secret' });
});

// --- "none" mode fail-closed invariant: an explicit second opt-in AND a provably loopback
// HOST are both required, so a single flipped env var can never make an Internet-reachable
// server unauthenticated. See loadInboundAuthConfig()'s docblock for the full rationale.

test('mode=none with no insecure opt-in at all is refused, even with a loopback HOST', () => {
  assert.throws(
    () => loadInboundAuthConfig({ MCP_INBOUND_AUTH_MODE: 'none', HOST: '127.0.0.1' } as NodeJS.ProcessEnv),
    InboundAuthConfigError
  );
});

test('mode=none with the opt-in but HOST=0.0.0.0 is refused (the Docker/public bind address)', () => {
  assert.throws(
    () =>
      loadInboundAuthConfig({
        MCP_INBOUND_AUTH_MODE: 'none',
        MCP_ALLOW_INSECURE_LOCAL_AUTH: '1',
        HOST: '0.0.0.0',
      } as NodeJS.ProcessEnv),
    InboundAuthConfigError
  );
});

test('mode=none with the opt-in but HOST=:: (IPv6 any-address) is refused', () => {
  assert.throws(
    () =>
      loadInboundAuthConfig({
        MCP_INBOUND_AUTH_MODE: 'none',
        MCP_ALLOW_INSECURE_LOCAL_AUTH: '1',
        HOST: '::',
      } as NodeJS.ProcessEnv),
    InboundAuthConfigError
  );
});

test('mode=none with the opt-in and HOST=127.0.0.1 is allowed', () => {
  const config = loadInboundAuthConfig({
    MCP_INBOUND_AUTH_MODE: 'none',
    MCP_ALLOW_INSECURE_LOCAL_AUTH: '1',
    HOST: '127.0.0.1',
  } as NodeJS.ProcessEnv);
  assert.deepEqual(config, { mode: 'none' });
});

test('mode=none with the opt-in and HOST=::1 (IPv6 loopback) is allowed', () => {
  const config = loadInboundAuthConfig({
    MCP_INBOUND_AUTH_MODE: 'none',
    MCP_ALLOW_INSECURE_LOCAL_AUTH: '1',
    HOST: '::1',
  } as NodeJS.ProcessEnv);
  assert.deepEqual(config, { mode: 'none' });
});

test('mode=none with the opt-in and an unset HOST is allowed (main()\'s own default is 127.0.0.1, read from the same env)', () => {
  const config = loadInboundAuthConfig({
    MCP_INBOUND_AUTH_MODE: 'none',
    MCP_ALLOW_INSECURE_LOCAL_AUTH: '1',
  } as NodeJS.ProcessEnv);
  assert.deepEqual(config, { mode: 'none' });
});

test('mode=none with the opt-in and a non-loopback hostname is refused', () => {
  assert.throws(
    () =>
      loadInboundAuthConfig({
        MCP_INBOUND_AUTH_MODE: 'none',
        MCP_ALLOW_INSECURE_LOCAL_AUTH: '1',
        HOST: 'design-core-mcp',
      } as NodeJS.ProcessEnv),
    InboundAuthConfigError
  );
});

test('mode=none with the opt-in and a LAN IP is refused', () => {
  assert.throws(
    () =>
      loadInboundAuthConfig({
        MCP_INBOUND_AUTH_MODE: 'none',
        MCP_ALLOW_INSECURE_LOCAL_AUTH: '1',
        HOST: '192.168.1.5',
      } as NodeJS.ProcessEnv),
    InboundAuthConfigError
  );
});

test('the insecure opt-in alone, without mode=none, has no effect on token mode', () => {
  const config = loadInboundAuthConfig({
    MCP_INBOUND_AUTH_MODE: 'token',
    MCP_INBOUND_TOKEN: 'a-real-secret',
    MCP_ALLOW_INSECURE_LOCAL_AUTH: '1',
    HOST: '0.0.0.0',
  } as NodeJS.ProcessEnv);
  assert.deepEqual(config, { mode: 'token', token: 'a-real-secret' });
});

test('none-mode rejection messages never contain a token value and are not secret-shaped', () => {
  try {
    loadInboundAuthConfig({ MCP_INBOUND_AUTH_MODE: 'none', MCP_ALLOW_INSECURE_LOCAL_AUTH: '1', HOST: '0.0.0.0' } as NodeJS.ProcessEnv);
    assert.fail('expected loadInboundAuthConfig to throw');
  } catch (error) {
    assert.ok(error instanceof InboundAuthConfigError);
    assert.doesNotMatch((error as Error).message, /[A-Za-z0-9+/]{32,}/, 'error message must not contain anything token-shaped');
  }
});

test('an unrecognized auth mode fails closed rather than silently defaulting to open', () => {
  assert.throws(
    () => loadInboundAuthConfig({ MCP_INBOUND_AUTH_MODE: 'basic' } as NodeJS.ProcessEnv),
    InboundAuthConfigError
  );
});

test('the fail-closed error message never contains a token value', () => {
  try {
    loadInboundAuthConfig({ MCP_INBOUND_AUTH_MODE: 'token', MCP_INBOUND_TOKEN: '' } as NodeJS.ProcessEnv);
    assert.fail('expected loadInboundAuthConfig to throw');
  } catch (error) {
    assert.ok(error instanceof InboundAuthConfigError);
    // The message is a fixed, static string about what's missing -- nothing here is ever
    // derived from a real secret, so there is nothing to accidentally interpolate.
    assert.doesNotMatch(error.message, /[A-Za-z0-9+/]{32,}/, 'error message must not contain anything token-shaped');
  }
});
