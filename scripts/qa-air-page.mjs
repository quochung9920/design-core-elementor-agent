import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';

const target = process.argv[2] || 'http://localhost:8087/?page_id=2';
const sourceFile = process.argv[3];
const widths = [1440, 1366, 1024, 767, 390];
const expectedRoots = ['dc-air-hero','dc-air-jumpbar','dc-what-section','dc-why-section','dc-compare-section','dc-conversion-section','dc-costs-section','dc-process-section','dc-transit-section','dc-faq-section','dc-enquiry-section'];
const results = []; const failures = [];
const browser = await chromium.launch({ headless: true });

const sectionHeights = async (page, source = false) => page.evaluate(({ source, expectedRoots }) => {
  if (source) {
    const selectors = {hero:'.hero',jump:'.jumpbar',what:'#what',why:'#why',compare:'#compare',conversion:'.conversion',costs:'#costs',process:'#process',transit:'#transit',faq:'#questions',footer:'.footer'};
    return Object.fromEntries(Object.entries(selectors).map(([key, selector]) => [key, Math.round(document.querySelector(selector)?.getBoundingClientRect().height || 0)]));
  }
  return Object.fromEntries(expectedRoots.map((name) => [name, Math.round(document.querySelector(`.${name}`)?.getBoundingClientRect().height || 0)]));
}, { source, expectedRoots });

for (const width of widths) {
  const context = await browser.newContext({ viewport: { width, height: 1000 } });
  const page = await context.newPage(); const consoleErrors = []; const requestFailures = [];
  page.on('console', message => { if (message.type() === 'error') consoleErrors.push(message.text()); });
  page.on('pageerror', error => consoleErrors.push(error.message));
  page.on('requestfailed', request => requestFailures.push(`${request.url()} ${request.failure()?.errorText || ''}`));
  const response = await page.goto(target, { waitUntil: 'networkidle' });
  let totalHeight = await page.evaluate(() => document.documentElement.scrollHeight);
  for (let y = 0; y < totalHeight; y += 700) { await page.evaluate(value => window.scrollTo(0, value), y); await page.waitForTimeout(25); }
  await page.evaluate(() => window.scrollTo(0, 0)); await page.waitForTimeout(120);
  totalHeight = await page.evaluate(() => document.documentElement.scrollHeight);
  const targetScreenshot = `/tmp/design-core-air-target-${width}.png`;
  await page.screenshot({ path: targetScreenshot, fullPage: true });

  const state = await page.evaluate((expectedRoots) => {
    const roots = expectedRoots.map(name => document.querySelectorAll(`.${name}`).length);
    const processImage = document.querySelector('.dc-process-image img');
    const stepMarks = [...document.querySelectorAll('.dc-step-mark')].map(e => e.getBoundingClientRect());
    const ticks = [...document.querySelectorAll('.dc-cost-tick')].map(e => e.getBoundingClientRect());
    const visible = selector => { const e=document.querySelector(selector); return !!e && e.getBoundingClientRect().width > 0 && getComputedStyle(e).display !== 'none'; };
    return {
      roots, scrollWidth: document.documentElement.scrollWidth, innerWidth,
      header: document.querySelectorAll('.elementor-location-header').length,
      footer: document.querySelectorAll('.elementor-location-footer').length,
      forms: document.querySelectorAll('.elementor-widget-form').length,
      processNatural: processImage ? [processImage.naturalWidth, processImage.naturalHeight] : [0,0],
      maxStepMarkWidth: Math.max(0, ...stepMarks.map(r => r.width)), maxTickWidth: Math.max(0, ...ticks.map(r => r.width)),
      desktopTransit: visible('.dc-transit-table'), mobileTransit: visible('.dc-transit-cards'),
      mobileToggle: visible('.dc-site-primary-menu .elementor-menu-toggle'),
    };
  }, expectedRoots);
  if (!response || response.status() !== 200) failures.push(`${width}: HTTP status is not 200`);
  if (consoleErrors.length) failures.push(`${width}: console errors: ${consoleErrors.join(' | ')}`);
  if (requestFailures.length) failures.push(`${width}: request failures: ${requestFailures.join(' | ')}`);
  if (state.scrollWidth > state.innerWidth + 1) failures.push(`${width}: horizontal overflow ${state.scrollWidth}/${state.innerWidth}`);
  if (state.roots.some(count => count !== 1)) failures.push(`${width}: missing or duplicate page root ${JSON.stringify(state.roots)}`);
  if (state.header !== 1 || state.footer !== 1) failures.push(`${width}: Header/Footer location count mismatch`);
  if (state.forms !== 3) failures.push(`${width}: expected three native Elementor Pro forms`);
  if (state.processNatural[0] < 1 || state.processNatural[1] < 1) failures.push(`${width}: process image did not lazy-load`);
  if (state.maxStepMarkWidth > 40 || state.maxTickWidth > 14) failures.push(`${width}: decorative marker stretched`);
  if (width <= 767 ? (!state.mobileTransit || state.desktopTransit) : (state.mobileTransit || !state.desktopTransit)) failures.push(`${width}: transit responsive visibility mismatch`);
  if (width <= 1024 && !state.mobileToggle) failures.push(`${width}: primary mobile menu toggle is unavailable`);

  const jump = page.locator('.dc-air-jumpbar').first();
  await page.evaluate(() => window.scrollTo(0, 1000)); await page.waitForTimeout(300);
  const activeJump = page.locator('.dc-air-jumpbar.elementor-sticky--active');
  const stickyTop = await activeJump.count() ? await activeJump.evaluate(e => Math.round(e.getBoundingClientRect().top)) : null;
  if (null === stickyTop || Math.abs(stickyTop) > 2) failures.push(`${width}: jumpbar is not sticky at top (${stickyTop})`);

  if (width <= 1024) {
    const toggle = page.locator('.dc-site-primary-menu .elementor-menu-toggle').first();
    await toggle.scrollIntoViewIfNeeded(); await toggle.click(); await page.waitForTimeout(120);
    const expanded = await toggle.getAttribute('aria-expanded');
    if (expanded !== 'true') failures.push(`${width}: mobile menu did not expand`);
    if (expanded === 'true') { await toggle.click(); await page.waitForTimeout(80); }
  }
  const faqTitle = page.locator('.dc-faq-accordion .elementor-tab-title').first();
  await faqTitle.scrollIntoViewIfNeeded();
  if ((await faqTitle.getAttribute('aria-expanded')) !== 'true') { await faqTitle.click(); await page.waitForTimeout(150); }
  if ((await faqTitle.getAttribute('aria-expanded')) !== 'true' && (await faqTitle.getAttribute('aria-selected')) !== 'true') failures.push(`${width}: FAQ did not expand`);
  const input = page.locator('.dc-enquiry-form input:not([type="hidden"])').first(); await input.focus(); await page.waitForTimeout(220);
  const focusShadow = await input.evaluate(e => getComputedStyle(e).boxShadow);
  if (!focusShadow || focusShadow === 'none') failures.push(`${width}: form focus state missing`);

  if (width >= 1366) {
    const hoverCases = [
      ['header button','.dc-site-header-cta .elementor-button','transform'], ['why card','.dc-why-card','transform'],
      ['diagram item','.dc-diagram-item','transform'], ['compare row','.dc-compare-row-1','backgroundColor'],
      ['process image','.dc-process-image img','transform'], ['process step','.dc-process-step','backgroundColor'],
      ['transit row','.dc-transit-row:not(.dc-transit-header)','backgroundColor'], ['faq item','.dc-faq-accordion .elementor-accordion-item','backgroundColor'],
      ['footer link','.dc-footer-menu .elementor-item','color'],
    ];
    for (const [label, selector, property] of hoverCases) {
      const locator = page.locator(selector).first(); await locator.scrollIntoViewIfNeeded();
      const before = await locator.evaluate((e,p) => getComputedStyle(e)[p], property); await locator.hover(); await page.waitForTimeout(230);
      const after = await locator.evaluate((e,p) => getComputedStyle(e)[p], property);
      if (before === after || (property === 'transform' && after === 'none')) failures.push(`${width}: ${label} hover missing`);
    }
  }

  let sourceHeight = 0; let sourceSections = null; let sourceScreenshot = null;
  if (sourceFile) {
    const sourceContext = await browser.newContext({ viewport: { width, height: 1000 }, javaScriptEnabled: false });
    await sourceContext.route(/^https?:\/\//, route => route.abort());
    const sourcePage = await sourceContext.newPage(); await sourcePage.goto(pathToFileURL(sourceFile).href, { waitUntil: 'load' });
    sourceHeight = await sourcePage.evaluate(() => document.documentElement.scrollHeight); sourceSections = await sectionHeights(sourcePage, true);
    sourceScreenshot = `/tmp/design-core-air-source-${width}.png`; await sourcePage.screenshot({ path: sourceScreenshot, fullPage: true }); await sourceContext.close();
    const ratio = Math.abs(totalHeight - sourceHeight) / sourceHeight;
    if (ratio > 0.1) failures.push(`${width}: total height differs from source by ${(ratio*100).toFixed(1)}%`);
  }
  results.push({ width, totalHeight, sourceHeight, state, stickyTop, focusShadow, consoleErrors, requestFailures, sourceSections, targetSections: await sectionHeights(page, false), screenshots: { source: sourceScreenshot, target: targetScreenshot } });
  await context.close();
}
await browser.close();
console.log(JSON.stringify({ status: failures.length ? 'fail' : 'pass', failures, results }, null, 2));
if (failures.length) process.exit(1);
