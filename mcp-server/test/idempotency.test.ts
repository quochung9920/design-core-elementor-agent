import { test } from 'node:test';
import assert from 'node:assert/strict';
import { deriveIdempotencyKey, resolveIdempotencyKey } from '../src/idempotency.js';

test('deriveIdempotencyKey is deterministic for identical tool + args within the same time bucket', () => {
  const a = deriveIdempotencyKey('design_core_update_page', { page_id: 5, preview_id: 'pv_1', plan_hash: 'h1' });
  const b = deriveIdempotencyKey('design_core_update_page', { page_id: 5, preview_id: 'pv_1', plan_hash: 'h1' });
  assert.equal(a, b);
});

test('deriveIdempotencyKey differs for different arguments', () => {
  const a = deriveIdempotencyKey('design_core_update_page', { page_id: 5, preview_id: 'pv_1', plan_hash: 'h1' });
  const b = deriveIdempotencyKey('design_core_update_page', { page_id: 6, preview_id: 'pv_1', plan_hash: 'h1' });
  assert.notEqual(a, b);
});

test('deriveIdempotencyKey differs across tools for the same arguments', () => {
  const args = { page_id: 5, confirm: true };
  const a = deriveIdempotencyKey('design_core_update_page', args);
  const b = deriveIdempotencyKey('design_core_publish_page', args);
  assert.notEqual(a, b);
});

test('resolveIdempotencyKey prefers an explicit idempotency_key over the derived default', () => {
  const explicit = resolveIdempotencyKey('design_core_update_page', { idempotency_key: 'my-stable-key', page_id: 5 });
  assert.equal(explicit, 'my-stable-key');
});

test('resolveIdempotencyKey derives a key when none is supplied', () => {
  const derived = resolveIdempotencyKey('design_core_update_page', { page_id: 5 });
  assert.equal(derived.length, 40);
});
