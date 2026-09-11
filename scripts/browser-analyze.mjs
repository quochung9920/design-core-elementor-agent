import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
import { readFileSync } from 'node:fs';

const file = process.argv[2];
const MAX_ELEMENTS = 2500;
const MAX_VIEWPORTS = 8;
const widths = (process.argv[3] || '1440,1366,1024,767,390').split(',').map(Number).filter(width => Number.isFinite(width) && width >= 240 && width <= 7680).slice(0, MAX_VIEWPORTS);
if (!file) { console.error('Missing HTML file'); process.exit(2); }

function preflightHtmlComplexity(html) {
  const MAX_SOURCE_BYTES = 2 * 1024 * 1024;
  const MAX_DEPTH = 128;
  const voidTags = new Set(['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr']);
  if (Buffer.byteLength(html, 'utf8') > MAX_SOURCE_BYTES) throw new Error('source-byte-limit-exceeded');
  let elements = 0, depth = 0, offset = 0;
  const openTags = [];
  while (offset < html.length) {
    const start = html.indexOf('<', offset); if (start < 0) break;
    if (html.startsWith('<!--', start)) { const end = html.indexOf('-->', start + 4); offset = end < 0 ? html.length : end + 3; continue; }
    let cursor = start + 1, closing = false;
    while (cursor < html.length && /\s/.test(html[cursor])) cursor++;
    if (html[cursor] === '/') { closing = true; cursor++; while (cursor < html.length && /\s/.test(html[cursor])) cursor++; }
    if (cursor >= html.length || !/[A-Za-z]/.test(html[cursor])) { offset = start + 1; continue; }
    const nameStart = cursor; while (cursor < html.length && /[A-Za-z0-9:_-]/.test(html[cursor])) cursor++;
    const tag = html.slice(nameStart, cursor).toLowerCase(); let quote = '', end = cursor;
    for (; end < html.length; end++) { const char = html[end]; if (quote) { if (char === quote) quote = ''; continue; } if (char === '"' || char === "'") { quote = char; continue; } if (char === '>') break; }
    if (end >= html.length) break;
    if (closing) { if (openTags.length && openTags[openTags.length - 1] === tag) { openTags.pop(); depth = openTags.length; } offset = end + 1; continue; }
    if (++elements > MAX_ELEMENTS) throw new Error(`element-limit-exceeded:${elements}:${MAX_ELEMENTS}`);
    const beforeEnd = html.slice(cursor, end).trimEnd();
    if (!voidTags.has(tag) && !beforeEnd.endsWith('/')) { openTags.push(tag); depth = openTags.length; if (depth > MAX_DEPTH) throw new Error(`depth-limit-exceeded:${depth}:${MAX_DEPTH}`); }
    offset = end + 1;
  }
}

try { preflightHtmlComplexity(readFileSync(file, 'utf8')); }
catch (error) { console.error(error instanceof Error ? error.message : String(error)); process.exit(4); }

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ javaScriptEnabled: false });
await context.route(/^https?:\/\//, route => route.abort());
const page = await context.newPage();
const results = {};
try {
  for (const width of widths) {
    await page.setViewportSize({ width, height: 1200 });
    await page.goto(pathToFileURL(file).href, { waitUntil: 'load' });
    results[width] = await page.evaluate((maxElements) => {
    const props = ['display','position','flexDirection','flexWrap','justifyContent','alignItems','gap','gridTemplateColumns','gridTemplateRows','width','height','minWidth','maxWidth','minHeight','maxHeight','marginTop','marginRight','marginBottom','marginLeft','paddingTop','paddingRight','paddingBottom','paddingLeft','fontFamily','fontSize','fontWeight','lineHeight','letterSpacing','color','backgroundColor','backgroundImage','backgroundSize','backgroundPosition','borderTopWidth','borderTopStyle','borderTopColor','borderRadius','boxShadow','opacity','overflow','objectFit','objectPosition','aspectRatio','zIndex','textAlign','textTransform','transform','transition'];
    const ignored = new Set(['style','script','link','meta','title','base','noscript','template']);
    const isVisibleContractNode = (element) => element instanceof Element && !ignored.has(element.tagName.toLowerCase());
    const domPath = (element) => {
      const parts = [];
      for (let node = element; node && node !== document.body; node = node.parentElement) {
        const siblings = node.parentElement ? [...node.parentElement.children].filter(isVisibleContractNode) : [];
        parts.unshift(`${node.tagName.toLowerCase()}[${siblings.indexOf(node) + 1}]`);
      }
      return `/body/${parts.join('/')}`;
    };
    const elements = [...document.querySelectorAll('body *')].filter(isVisibleContractNode);
    if (elements.length > maxElements) { throw new Error(`element-limit-exceeded:${elements.length}:${maxElements}`); }
    return elements.map((el, index) => {
      const cs = getComputedStyle(el); const r = el.getBoundingClientRect(); const styles = {};
      for (const p of props) styles[p] = cs[p];
      return { index, domPath: domPath(el), tag: el.tagName.toLowerCase(), id: el.id || '', classes: [...el.classList], text: (el.textContent || '').trim().slice(0, 240), rect: { x:r.x, y:r.y, width:r.width, height:r.height }, naturalWidth: el instanceof HTMLImageElement ? el.naturalWidth : 0, naturalHeight: el instanceof HTMLImageElement ? el.naturalHeight : 0, styles };
    });
    }, MAX_ELEMENTS);
  }
  console.log(JSON.stringify({ schema_version: 4, javascript_enabled: false, network_enabled: false, dom_path_contract: 'visible-design-ir-nodes-v1', viewports: results }));
} catch (error) {
  console.error(error instanceof Error ? error.message : String(error));
  process.exitCode = 4;
} finally {
  await context.close();
  await browser.close();
}
