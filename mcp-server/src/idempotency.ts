import { createHash } from 'node:crypto';

/**
 * Default Idempotency-Key derivation for write tools when the caller doesn't supply
 * one explicitly. A ChatGPT-initiated retry of the same logical tool call generally
 * arrives as a fresh JSON-RPC request with identical arguments, so hashing the tool
 * name + arguments (bucketed by time, so a legitimate later re-run of the same
 * operation isn't blocked forever) gives real duplicate-mutation protection without
 * requiring the caller to think about it. An explicit `idempotency_key` argument
 * always wins.
 */
export function deriveIdempotencyKey(toolName: string, args: unknown, bucketMinutes = 10): string {
  const bucket = Math.floor(Date.now() / (bucketMinutes * 60_000));
  const payload = `${toolName}|${JSON.stringify(args)}|${bucket}`;
  return createHash('sha256').update(payload).digest('hex').slice(0, 40);
}

export function resolveIdempotencyKey(toolName: string, args: { idempotency_key?: string } & Record<string, unknown>): string {
  return args.idempotency_key && args.idempotency_key.trim() !== '' ? args.idempotency_key.trim() : deriveIdempotencyKey(toolName, args);
}
