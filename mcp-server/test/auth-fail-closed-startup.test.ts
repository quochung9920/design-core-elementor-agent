import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, type ChildProcessByStdio } from 'node:child_process';
import type { Readable } from 'node:stream';
import { createConnection } from 'node:net';
import { fileURLToPath } from 'node:url';

/**
 * Real-process proof that a misconfigured "none" auth mode can never bind a real TCP socket.
 * The unit tests in inbound-auth.test.ts prove loadInboundAuthConfig() throws for the right
 * inputs; this file proves the actual compiled server.js, spawned as its own OS process,
 * really does exit before ever opening a listening port for those same inputs -- and really
 * does listen (and accept a real connection) for the configurations that must be allowed.
 */

const SERVER_ENTRY = fileURLToPath(new URL('../src/server.js', import.meta.url));

/** A clean slate: strip anything auth/host/port-related this process's own shell might carry
 *  (e.g. a developer's sourced .env), so every test's env is exactly what it declares. */
function baseEnv(): NodeJS.ProcessEnv {
  const env = { ...process.env };
  delete env.MCP_INBOUND_AUTH_MODE;
  delete env.MCP_INBOUND_TOKEN;
  delete env.MCP_ALLOW_INSECURE_LOCAL_AUTH;
  delete env.HOST;
  delete env.PORT;
  return env;
}

function canConnect(port: number, host: string): Promise<boolean> {
  return new Promise((resolve) => {
    const socket = createConnection({ port, host, timeout: 800 });
    socket.on('connect', () => {
      socket.destroy();
      resolve(true);
    });
    socket.on('error', () => resolve(false));
    socket.on('timeout', () => {
      socket.destroy();
      resolve(false);
    });
  });
}

/** A 0.0.0.0/:: bind also accepts loopback connections; connecting to the wildcard address
 *  itself as a client is not portable, so probe via the equivalent loopback address instead. */
function connectTargetFor(bindHost: string): string {
  if (bindHost === '0.0.0.0') return '127.0.0.1';
  if (bindHost === '::') return '::1';
  return bindHost;
}

function spawnServer(extraEnv: Record<string, string>, port: number): ChildProcessByStdio<null, Readable, Readable> {
  return spawn(process.execPath, [SERVER_ENTRY], {
    env: { ...baseEnv(), PORT: String(port), ...extraEnv },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
}

/** Asserts the process exits on its own (fail-closed), quickly, without ever logging a
 *  successful bind or opening a real listening socket on `port`. */
async function expectRefusedStartup(extraEnv: Record<string, string>, port: number): Promise<{ stdout: string; stderr: string }> {
  const child = spawnServer(extraEnv, port);
  let stdout = '';
  let stderr = '';
  child.stdout.on('data', (d) => (stdout += d.toString()));
  child.stderr.on('data', (d) => (stderr += d.toString()));

  const exitCode = await new Promise<number | null>((resolve) => {
    const timer = setTimeout(() => {
      child.kill();
      resolve(undefined as unknown as null);
    }, 4000);
    child.on('exit', (code) => {
      clearTimeout(timer);
      resolve(code);
    });
  });

  assert.ok(exitCode !== undefined, 'the process must exit on its own (fail closed) rather than hang until this test kills it');
  assert.notEqual(exitCode, 0, `a refused config must exit non-zero; got ${exitCode}. stderr: ${stderr}`);
  assert.doesNotMatch(stdout, /Listening on/, 'a refused config must never log a successful bind');
  const connectable = await canConnect(port, '127.0.0.1');
  assert.equal(connectable, false, 'no TCP listening socket may exist on the target port for a refused config');
  return { stdout, stderr };
}

/** Asserts the process actually starts, logs a successful bind, and accepts a real TCP
 *  connection on `host`:`port`. Returns a cleanup function that kills the child. */
async function expectSuccessfulStartup(extraEnv: Record<string, string>, port: number, host: string): Promise<() => void> {
  const child = spawnServer(extraEnv, port);
  let stdout = '';
  let stderr = '';
  child.stdout.on('data', (d) => (stdout += d.toString()));
  child.stderr.on('data', (d) => (stderr += d.toString()));
  let exited: number | null | undefined;
  child.on('exit', (code) => {
    exited = code;
  });

  const listened = await new Promise<boolean>((resolve) => {
    const timer = setTimeout(() => resolve(false), 4000);
    const check = setInterval(() => {
      if (/Listening on/.test(stdout)) {
        clearInterval(check);
        clearTimeout(timer);
        resolve(true);
      }
      if (exited !== undefined) {
        clearInterval(check);
        clearTimeout(timer);
        resolve(false);
      }
    }, 50);
  });

  if (!listened) {
    child.kill();
    assert.fail(`expected a successful bind; exited=${String(exited)} stdout=${stdout} stderr=${stderr}`);
  }
  const connectable = await canConnect(port, connectTargetFor(host));
  if (!connectable) {
    child.kill();
    assert.fail(`server logged a successful bind but a real connection to ${host}:${port} failed`);
  }
  return () => child.kill();
}

test('B: mode=token with no MCP_INBOUND_TOKEN refuses startup, no listening socket', async () => {
  await expectRefusedStartup({ MCP_INBOUND_AUTH_MODE: 'token' }, 39101);
});

test('C: mode=none with no insecure opt-in refuses startup, no listening socket', async () => {
  await expectRefusedStartup({ MCP_INBOUND_AUTH_MODE: 'none', HOST: '127.0.0.1' }, 39102);
});

test('D: mode=none + opt-in + HOST=0.0.0.0 refuses startup, no listening socket (the Docker bind address)', async () => {
  const { stderr } = await expectRefusedStartup(
    { MCP_INBOUND_AUTH_MODE: 'none', MCP_ALLOW_INSECURE_LOCAL_AUTH: '1', HOST: '0.0.0.0' },
    39103
  );
  assert.match(stderr, /Refusing to start/);
});

test('E: mode=none + opt-in + HOST=:: refuses startup, no listening socket', async () => {
  await expectRefusedStartup({ MCP_INBOUND_AUTH_MODE: 'none', MCP_ALLOW_INSECURE_LOCAL_AUTH: '1', HOST: '::' }, 39104);
});

test('H: mode=none + opt-in + a non-loopback hostname refuses startup', async () => {
  await expectRefusedStartup(
    { MCP_INBOUND_AUTH_MODE: 'none', MCP_ALLOW_INSECURE_LOCAL_AUTH: '1', HOST: 'design-core-mcp' },
    39105
  );
});

test('F: mode=none + opt-in + HOST=127.0.0.1 is allowed and really listens', async () => {
  const stop = await expectSuccessfulStartup(
    { MCP_INBOUND_AUTH_MODE: 'none', MCP_ALLOW_INSECURE_LOCAL_AUTH: '1' },
    39106,
    '127.0.0.1'
  );
  stop();
});

test('G: mode=none + opt-in + HOST=::1 is allowed if this environment supports IPv6 loopback', async (t) => {
  const child = spawnServer({ MCP_INBOUND_AUTH_MODE: 'none', MCP_ALLOW_INSECURE_LOCAL_AUTH: '1', HOST: '::1' }, 39107);
  let stdout = '';
  let stderr = '';
  child.stdout.on('data', (d) => (stdout += d.toString()));
  child.stderr.on('data', (d) => (stderr += d.toString()));
  let exited: number | null | undefined;
  child.on('exit', (code) => {
    exited = code;
  });
  const listened = await new Promise<boolean>((resolve) => {
    const timer = setTimeout(() => resolve(false), 3000);
    const check = setInterval(() => {
      if (/Listening on/.test(stdout) || exited !== undefined) {
        clearInterval(check);
        clearTimeout(timer);
        resolve(/Listening on/.test(stdout));
      }
    }, 50);
  });
  // Never auth-rejected either way -- ::1 is on the loopback allowlist, so if this fails it
  // must be this environment's own IPv6 support, never the auth invariant.
  assert.doesNotMatch(stderr, /Refusing to start/, 'HOST=::1 must never be treated as non-loopback');
  if (!listened) {
    child.kill();
    t.skip('this environment does not appear to support binding ::1 (unrelated to the auth invariant)');
    return;
  }
  const connectable = await canConnect(39107, '::1');
  child.kill();
  assert.ok(connectable, 'expected a real IPv6 loopback connection once the server logged a successful bind');
});

test('A/I: authenticated Docker/public-shaped config (HOST=0.0.0.0, mode=token, real token) starts normally', async () => {
  const stop = await expectSuccessfulStartup(
    { MCP_INBOUND_AUTH_MODE: 'token', MCP_INBOUND_TOKEN: 'a-real-secret-token-value-for-this-test-only' },
    39108,
    '0.0.0.0'
  );
  stop();
});

test('J: none of the refused-startup stderr/stdout above ever contained a token-shaped value', async () => {
  const cases: Array<Record<string, string>> = [
    { MCP_INBOUND_AUTH_MODE: 'token' },
    { MCP_INBOUND_AUTH_MODE: 'none', HOST: '127.0.0.1' },
    { MCP_INBOUND_AUTH_MODE: 'none', MCP_ALLOW_INSECURE_LOCAL_AUTH: '1', HOST: '0.0.0.0' },
  ];
  let port = 39120;
  for (const extraEnv of cases) {
    const { stdout, stderr } = await expectRefusedStartup(extraEnv, port++);
    assert.doesNotMatch(stdout + stderr, /[A-Za-z0-9+/]{32,}/, `output for ${JSON.stringify(extraEnv)} must not contain anything token-shaped`);
  }
});
