import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
import fs from 'node:fs';

const target = process.argv[2];
const width = Number(process.argv[3] || 1440);
const output = process.argv[4];
if (!target || !output) { console.error('Usage: capture-page.mjs target width output.png'); process.exit(2); }
const isRemote = /^https?:\/\//i.test(target);
const targetUrl = isRemote ? new URL(target) : pathToFileURL(target);
const browser = await chromium.launch({ headless:true });
const context = await browser.newContext({ viewport:{ width, height:1200 }, deviceScaleFactor:1, javaScriptEnabled:isRemote });
if (isRemote) {
  const allowedOrigin = targetUrl.origin.toLowerCase();
  await context.route(/^https?:\/\//i, route => {
    try {
      const url = new URL(route.request().url());
      if (url.origin.toLowerCase() === allowedOrigin) return route.continue();
    } catch {}
    return route.abort('blockedbyclient');
  });
} else {
  await context.route(/^https?:\/\//i, route => route.abort('blockedbyclient'));
}
const page = await context.newPage();
page.setDefaultNavigationTimeout(45000);
await page.goto(targetUrl.href, { waitUntil:isRemote ? 'domcontentloaded' : 'load' });
if (isRemote) await page.waitForTimeout(600);
const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
await page.screenshot({ path:output, fullPage:true });
await context.close(); await browser.close();
if (!fs.existsSync(output)) process.exit(3);
console.log(JSON.stringify({ output, width, javascript_enabled:isRemote, network_policy:isRemote?'same-origin':'blocked', target_origin:isRemote?targetUrl.origin:'', horizontal_overflow:overflow }));
