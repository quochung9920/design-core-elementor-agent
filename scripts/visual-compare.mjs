import fs from 'node:fs';
import { PNG } from 'pngjs';
import pixelmatch from 'pixelmatch';

const [referencePath, candidatePath] = process.argv.slice(2);
if (!referencePath || !candidatePath) { console.error('Usage: node visual-compare.mjs ref.png candidate.png'); process.exit(2); }
const ref = PNG.sync.read(fs.readFileSync(referencePath));
const cand = PNG.sync.read(fs.readFileSync(candidatePath));
if (ref.width !== cand.width || ref.height !== cand.height) {
  console.log(JSON.stringify({ comparable:false, reason:'dimension-mismatch', reference:{width:ref.width,height:ref.height}, candidate:{width:cand.width,height:cand.height} }));
  process.exit(0);
}
const diff = new PNG({ width: ref.width, height: ref.height });
const changed = pixelmatch(ref.data, cand.data, diff.data, ref.width, ref.height, { threshold: 0.1 });
const total = ref.width * ref.height;
console.log(JSON.stringify({ comparable:true, changed_pixels:changed, total_pixels:total, difference_ratio: total ? changed / total : 0, similarity: total ? 1 - changed / total : 1 }));
