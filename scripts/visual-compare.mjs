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
  for (let i = 0; i < out.data.length; i += 4) {
    out.data[i] = 255; out.data[i + 1] = 255; out.data[i + 2] = 255; out.data[i + 3] = 255;
  }
  PNG.bitblt(image, out, 0, 0, image.width, image.height, 0, 0);
  return out;
}

function luma(data, index) {
  const alpha = data[index + 3] / 255;
  const r = data[index] * alpha + 255 * (1 - alpha);
  const g = data[index + 1] * alpha + 255 * (1 - alpha);
  const b = data[index + 2] * alpha + 255 * (1 - alpha);
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function perceptualMetrics(a, b) {
  const maxSamples = 80000;
  const step = Math.max(1, Math.ceil(Math.sqrt((width * height) / maxSamples)));
  let sum = 0; let samples = 0;
  const cols = 4; const rows = 4;
  const regions = Array.from({ length: cols * rows }, (_, i) => ({ index: i, sum: 0, samples: 0 }));

  for (let y = 0; y < height; y += step) {
    for (let x = 0; x < width; x += step) {
      const i = (y * width + x) * 4;
      const delta = Math.abs(luma(a.data, i) - luma(b.data, i));
      sum += delta; samples++;
      const col = Math.min(cols - 1, Math.floor((x / Math.max(1, width)) * cols));
      const row = Math.min(rows - 1, Math.floor((y / Math.max(1, height)) * rows));
      const region = regions[row * cols + col];
      region.sum += delta; region.samples++;
    }
  }

  const mae = samples ? sum / samples : 255;
  const perceptualSimilarity = Math.max(0, 1 - mae / 255);
  const regionScores = regions.map(region => {
    const row = Math.floor(region.index / cols);
    const col = region.index % cols;
    const x = Math.floor((col / cols) * width);
    const y = Math.floor((row / rows) * height);
    const x2 = Math.floor(((col + 1) / cols) * width);
    const y2 = Math.floor(((row + 1) / rows) * height);
    const regionMae = region.samples ? region.sum / region.samples : 255;
    return {
      row, col, x, y, width: x2 - x, height: y2 - y,
      perceptual_similarity: Math.max(0, 1 - regionMae / 255)
    };
  });
  const worstRegions = [...regionScores].sort((a, b) => a.perceptual_similarity - b.perceptual_similarity).slice(0, 6);
  return { perceptualSimilarity, regionScores, worstRegions, sampleStep: step };
}

const a = canvas(ref); const b = canvas(cand); const diff = new PNG({ width, height });
const changed = pixelmatch(a.data, b.data, diff.data, width, height, { threshold: 0.1, includeAA: false });
const pixelDifference = total ? changed / total : 0;
const pixelSimilarity = Math.max(0, 1 - pixelDifference);
const refArea = ref.width * ref.height; const candArea = cand.width * cand.height; const maxArea = Math.max(refArea, candArea, 1);
const dimensionPenalty = 1 - Math.min(refArea, candArea) / maxArea;
const dimensionSimilarity = Math.max(0, 1 - dimensionPenalty);
const perceptual = perceptualMetrics(a, b);

// Geometry is verified separately using exact Figma-node ownership. This image
// score combines strict pixels, perceptual luminance and dimension agreement so
// antialiasing differences do not dominate while large visual shifts still fail.
const compositeSimilarity = Math.max(0, Math.min(1,
  0.45 * pixelSimilarity + 0.45 * perceptual.perceptualSimilarity + 0.10 * dimensionSimilarity
));
const legacySimilarity = Math.max(0, 1 - Math.max(pixelDifference, dimensionPenalty));

console.log(JSON.stringify({
  version: 4,
  comparable: true,
  resized_for_comparison: ref.width !== cand.width || ref.height !== cand.height,
  reference: { width: ref.width, height: ref.height },
  candidate: { width: cand.width, height: cand.height },
  changed_pixels: changed,
  total_pixels: total,
  pixel_difference_ratio: pixelDifference,
  pixel_similarity: pixelSimilarity,
  perceptual_similarity: perceptual.perceptualSimilarity,
  dimension_penalty: dimensionPenalty,
  dimension_similarity: dimensionSimilarity,
  legacy_similarity: legacySimilarity,
  difference_ratio: Math.max(0, 1 - compositeSimilarity),
  similarity: compositeSimilarity,
  region_grid: { rows: 4, cols: 4, sample_step: perceptual.sampleStep },
  region_scores: perceptual.regionScores,
  worst_regions: perceptual.worstRegions
}));
