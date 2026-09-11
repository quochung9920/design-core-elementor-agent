import rateLimit from 'express-rate-limit';

/**
 * Bounded, per-source-IP rate limiting for the public /mcp endpoint.
 *
 * Uses express-rate-limit's default in-memory store: a Map keyed by client IP with a fixed
 * window and its own periodic sweep of expired entries, so memory is bounded by the number of
 * distinct recent clients rather than growing unboundedly. req.ip is only trustworthy here
 * because server.ts scopes Express's `trust proxy` to exactly the known Caddy hop -- see the
 * comment there; an unscoped `trust proxy: true` would let a caller spoof X-Forwarded-For and
 * get a fresh bucket per request, defeating this entirely.
 *
 * Mounted before requireInboundAuth() (see server.ts) so a flood of *wrong* tokens is bounded
 * by the same policy as a flood of valid ones, rather than skipping rate limiting entirely
 * because auth rejects first.
 */
export function createMcpRateLimiter(windowMs = 60_000, limit = 60) {
  return rateLimit({
    windowMs,
    limit,
    standardHeaders: true, // RateLimit-* response headers, and Retry-After on 429
    legacyHeaders: false,
    message: { error: 'rate_limited', message: 'Too many requests. Please retry later.' },
  });
}
