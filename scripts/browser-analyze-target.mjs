import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
import fs from 'node:fs';

const target = process.argv[2];
const widths = (process.argv[3] || '1440,1024,767,390').split(',').map(Number).filter(w => Number.isFinite(w) && w >= 240 && w <= 7680).slice(0, 8);
const extraHosts = (process.argv[4] || '').split(',').map(v => v.trim().toLowerCase()).filter(Boolean);
if (!target) { console.error('Missing target'); process.exit(2); }

const isRemote = /^https?:\/\//i.test(target);
let targetUrl;
try { targetUrl = isRemote ? new URL(target) : pathToFileURL(fs.realpathSync(target)); }
catch { console.error('Invalid target'); process.exit(2); }

const allowedHosts = new Set(extraHosts);
allowedHosts.add('fonts.googleapis.com');
allowedHosts.add('fonts.gstatic.com');
const targetOrigin = isRemote ? targetUrl.origin.toLowerCase() : '';
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ javaScriptEnabled: isRemote });

if (isRemote) {
  await context.route(/^https?:\/\//i, async route => {
    try {
      const request = route.request();
      const url = new URL(request.url());
      if (url.origin.toLowerCase() === targetOrigin) return route.continue();
      if (allowedHosts.has(url.hostname.toLowerCase()) && ['font', 'image', 'stylesheet', 'media'].includes(request.resourceType())) return route.continue();
    } catch {}
    return route.abort('blockedbyclient');
  });
} else {
  await context.route(/^https?:\/\//i, route => route.abort('blockedbyclient'));
}

const page = await context.newPage();
page.setDefaultNavigationTimeout(45000);
const results = {};
const MAX_ELEMENTS = 3500;
try {
  for (const width of widths) {
    await page.setViewportSize({ width, height: 1200 });
    await page.goto(targetUrl.href, { waitUntil: isRemote ? 'domcontentloaded' : 'load' });
    if (isRemote) {
      await page.evaluate(async () => { try { if (document.fonts?.ready) await document.fonts.ready; } catch {} });
      await page.waitForTimeout(350);
    }
    results[width] = await page.evaluate((maxElements) => {
      const props = [
        'display','position','flexDirection','flexWrap','justifyContent','alignItems','gap','rowGap','columnGap',
        'gridTemplateColumns','gridTemplateRows','width','height','minWidth','maxWidth','minHeight','maxHeight',
        'marginTop','marginRight','marginBottom','marginLeft','paddingTop','paddingRight','paddingBottom','paddingLeft',
        'fontFamily','fontStyle','fontSize','fontWeight','lineHeight','letterSpacing','color','backgroundColor','backgroundImage',
        'backgroundSize','backgroundPosition','borderTopWidth','borderTopStyle','borderTopColor','borderRadius','boxShadow',
        'opacity','overflow','objectFit','objectPosition','aspectRatio','zIndex','textAlign','textTransform','transform'
      ];
      const domPath = (element) => {
        const parts = [];
        for (let node = element; node && node !== document.body; node = node.parentElement) {
          const siblings = node.parentElement ? [...node.parentElement.children] : [];
          parts.unshift(`${node.tagName.toLowerCase()}[${siblings.indexOf(node) + 1}]`);
        }
        return `/body/${parts.join('/')}`;
      };
      const normalizedText = value => (value || '').replace(/\s+/g, ' ').trim().slice(0, 240);
      const figmaClassOf = (element) => element ? ([...element.classList].find(c => c.startsWith('dc-figma-node-')) || '') : '';
      const primaryFontOf = value => {
        const first = String(value || '').split(',')[0]?.trim() || '';
        return first.replace(/^['"]|['"]$/g, '');
      };
      const normalizeFamily = value => String(value || '').trim().replace(/^['"]|['"]$/g, '').toLowerCase();
      const genericFamilies = new Set(['serif','sans-serif','monospace','cursive','fantasy','system-ui','ui-serif','ui-sans-serif','ui-monospace','ui-rounded','emoji','math','fangsong']);
      const declaredFamilies = new Set();
      try {
        document.fonts?.forEach(face => { const family = normalizeFamily(face.family); if (family) declaredFamilies.add(family); });
      } catch {}
      const metricCache = new Map();
      const metricProvesFont = family => {
        const key = normalizeFamily(family);
        if (!key) return false;
        if (genericFamilies.has(key)) return true;
        if (metricCache.has(key)) return metricCache.get(key);
        let proven = false;
        try {
          const canvas = document.createElement('canvas');
          const ctx = canvas.getContext('2d');
          if (ctx) {
            const sample = 'mmmmmmmmmmlliWW@@0123456789';
            const escaped = String(family).replace(/"/g, '\\"');
            const measure = font => { ctx.font = `72px ${font}`; return ctx.measureText(sample).width; };
            const mono = measure('monospace');
            const familyMono = measure(`"${escaped}", monospace`);
            const serif = measure('serif');
            const familySerif = measure(`"${escaped}", serif`);
            proven = Math.abs(familyMono - mono) > 0.25 || Math.abs(familySerif - serif) > 0.25;
          }
        } catch {}
        metricCache.set(key, proven);
        return proven;
      };
      const fontProof = (cs, primary) => {
        if (!primary) return { check: null, declared: false, metric: false, proven: null };
        const familyKey = normalizeFamily(primary);
        const declared = genericFamilies.has(familyKey) || declaredFamilies.has(familyKey);
        const metric = metricProvesFont(primary);
        if (!document.fonts?.check) return { check: null, declared, metric, proven: declared || metric };
        try {
          const family = primary.replace(/"/g, '\\"');
          const check = document.fonts.check(`${cs.fontStyle || 'normal'} ${cs.fontWeight || '400'} ${cs.fontSize || '16px'} "${family}"`);
          // FontFaceSet.check alone can be true when no matching @font-face blocks
          // rendering. Require either a declared loaded face or measurable glyph
          // metrics distinct from generic fallbacks before calling it proven.
          return { check, declared, metric, proven: !!check && (declared || metric) };
        } catch { return { check: null, declared, metric, proven: declared || metric }; }
      };

      const elements = document.querySelectorAll('body *');
      if (elements.length > maxElements) throw new Error(`element-limit-exceeded:${elements.length}:${maxElements}`);
      return [...elements].map((el, index) => {
        const cs = getComputedStyle(el); const r = el.getBoundingClientRect(); const styles = {};
        for (const p of props) styles[p] = cs[p];

        const owner = el.matches?.('[data-id],[data-elementor-id]') ? el : el.closest?.('[data-id],[data-elementor-id]');
        const elementorId = owner?.getAttribute('data-id') || owner?.getAttribute('data-elementor-id') || '';
        const elementorType = owner?.getAttribute('data-element_type') || '';
        const widgetRaw = owner?.getAttribute('data-widget_type') || '';
        const widgetType = widgetRaw ? widgetRaw.split('.')[0] : '';
        const parentElementorOwner = owner?.parentElement?.closest?.('[data-id],[data-elementor-id]');
        const parentElementorId = parentElementorOwner?.getAttribute('data-id') || parentElementorOwner?.getAttribute('data-elementor-id') || '';

        const figmaOwner = el.matches?.('[class*="dc-figma-node-"]') ? el : el.closest?.('[class*="dc-figma-node-"]');
        const figmaClass = figmaClassOf(figmaOwner);
        const figmaParent = figmaOwner?.parentElement?.closest?.('[class*="dc-figma-node-"]');
        const figmaParentClass = figmaClassOf(figmaParent);

        const primaryFont = primaryFontOf(cs.fontFamily);
        const proof = fontProof(cs, primaryFont);
        return {
          index, domPath: domPath(el), tag: el.tagName.toLowerCase(), id: el.id || '', classes: [...el.classList],
          text: normalizedText(el.textContent), ownText: normalizedText([...el.childNodes].filter(n => n.nodeType === Node.TEXT_NODE).map(n => n.textContent).join(' ')),
          elementorId, elementorType, widgetType, parentElementorId,
          figmaClass, figmaParentClass,
          fontFamilyPrimary: primaryFont,
          fontLoaded: proof.proven,
          fontCheck: proof.check,
          fontDeclared: proof.declared,
          fontMetricProven: proof.metric,
          rect: { x:r.x, y:r.y, width:r.width, height:r.height },
          naturalWidth: el instanceof HTMLImageElement ? el.naturalWidth : 0,
          naturalHeight: el instanceof HTMLImageElement ? el.naturalHeight : 0,
          styles
        };
      });
    }, MAX_ELEMENTS);
  }
  console.log(JSON.stringify({ schema_version: 7, target: targetUrl.href, javascript_enabled: isRemote, network_policy: isRemote ? 'same-origin-plus-explicit-static-hosts' : 'blocked', target_origin: targetOrigin, allowed_hosts: [...allowedHosts], viewports: results }));
} catch (error) {
  console.error(error instanceof Error ? error.message : String(error));
  process.exitCode = 4;
} finally {
  await context.close();
  await browser.close();
}
