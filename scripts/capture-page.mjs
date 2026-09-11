import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
import fs from 'node:fs';

const target = process.argv[2];
const width = Number(process.argv[3] || 1440);
const output = process.argv[4];
const selector = String(process.argv[5] || '').trim();
const allowedHosts = new Set(String(process.argv[6] || '').split(',').map(v => v.trim().toLowerCase()).filter(Boolean));
allowedHosts.add('fonts.googleapis.com');
allowedHosts.add('fonts.gstatic.com');
if (!target || !output) { console.error('Usage: capture-page.mjs target width output.png [selector] [asset-hosts]'); process.exit(2); }

const isRemote = /^https?:\/\//i.test(target);
const targetUrl = isRemote ? new URL(target) : pathToFileURL(target);
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width, height: 1200 }, deviceScaleFactor: 1, javaScriptEnabled: true });

if (isRemote) {
  const allowedOrigin = targetUrl.origin.toLowerCase();
  await context.route(/^https?:\/\//i, route => {
    try {
      const request = route.request();
      const url = new URL(request.url());
      if (url.origin.toLowerCase() === allowedOrigin) return route.continue();
      const type = request.resourceType();
      if (allowedHosts.has(url.hostname.toLowerCase()) && ['font', 'image', 'stylesheet', 'media'].includes(type)) return route.continue();
    } catch {}
    return route.abort('blockedbyclient');
  });
} else {
  await context.route(/^https?:\/\//i, route => route.abort('blockedbyclient'));
}

const page = await context.newPage();
page.setDefaultNavigationTimeout(45000);
await page.goto(targetUrl.href, { waitUntil: isRemote ? 'domcontentloaded' : 'load' });
if (isRemote) {
  await page.evaluate(async () => { try { if (document.fonts?.ready) await document.fonts.ready; } catch {} });
  await page.waitForTimeout(350);
}
await page.addStyleTag({ content: `*,*::before,*::after{animation-duration:0s!important;animation-delay:0s!important;transition:none!important;caret-color:transparent!important}html{scroll-behavior:auto!important}` });
const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
let box = null;
if (selector) {
  const targetNode = page.locator(selector).first();
  if (!(await targetNode.count())) { console.error(`Capture selector not found: ${selector}`); await context.close(); await browser.close(); process.exit(4); }
  await targetNode.scrollIntoViewIfNeeded();
  box = await targetNode.boundingBox();
  await targetNode.screenshot({ path: output, animations: 'disabled' });
} else {
  await page.screenshot({ path: output, fullPage: true, animations: 'disabled' });
}
await context.close(); await browser.close();
if (!fs.existsSync(output)) process.exit(3);
console.log(JSON.stringify({ output, width, selector, box, javascript_enabled: true, network_policy: isRemote ? 'same-origin-plus-explicit-static-assets' : 'blocked', target_origin: isRemote ? targetUrl.origin : '', horizontal_overflow: overflow }));
