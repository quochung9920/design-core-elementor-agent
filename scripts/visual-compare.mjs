import fs from 'node:fs';
import { PNG } from 'pngjs';
import pixelmatch from 'pixelmatch';

const [referencePath, candidatePath] = process.argv.slice(2);
if (!referencePath || !candidatePath) { console.error('Usage: node visual-compare.mjs ref.png candidate.png'); process.exit(2); }
const ref = PNG.sync.read(fs.readFileSync(referencePath));
const cand = PNG.sync.read(fs.readFileSync(candidatePath));
const width = Math.max(ref.width, cand.width);
const height = Math.max(ref.height, cand.height);
const total = width * height;

function canvas(image) {
  const out = new PNG({ width, height });
  for (let i = 0; i < out.data.length; i += 4) { out.data[i] = 255; out.data[i + 1] = 255; out.data[i + 2] = 255; out.data[i + 3] = 255; }
  PNG.bitblt(image, out, 0, 0, image.width, image.height, 0, 0);
  return out;
}
const a = canvas(ref); const b = canvas(cand); const diff = new PNG({ width, height });
const changed = pixelmatch(a.data, b.data, diff.data, width, height, { threshold: 0.1, includeAA: false });
const pixelDifference = total ? changed / total : 0;
const refArea = ref.width * ref.height; const candArea = cand.width * cand.height; const maxArea = Math.max(refArea, candArea, 1);
const dimensionPenalty = 1 - Math.min(refArea, candArea) / maxArea;
const differenceRatio = Math.max(pixelDifference, dimensionPenalty);
console.log(JSON.stringify({
  comparable: true,
  resized_for_comparison: ref.width !== cand.width || ref.height !== cand.height,
  reference: { width: ref.width, height: ref.height },
  candidate: { width: cand.width, height: cand.height },
  changed_pixels: changed,
  total_pixels: total,
  pixel_difference_ratio: pixelDifference,
  dimension_penalty: dimensionPenalty,
  difference_ratio: differenceRatio,
  similarity: Math.max(0, 1 - differenceRatio)
}));
