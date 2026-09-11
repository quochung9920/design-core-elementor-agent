import { chromium } from 'playwright';
import { existsSync } from 'node:fs';

try {
  const executable = chromium.executablePath();
  if (!executable || !existsSync(executable)) {
    console.error('playwright-chromium-executable-missing');
    process.exit(1);
  }
  console.log(JSON.stringify({ ok: true, executable }));
} catch (error) {
  console.error(error instanceof Error ? error.message : String(error));
  process.exit(1);
}
