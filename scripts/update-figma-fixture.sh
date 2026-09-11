#!/usr/bin/env bash
# Refresh the PawCare golden fixture from live Figma (requires FIGMA_TOKEN).
# Normal CI never calls this — ci-local.sh works offline from stored fixtures.
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FIXTURE="$ROOT/tests/fixtures/figma/pawcare-4-1049"
BENCH="${1:-pawcare}"
if [[ "$BENCH" != "pawcare" ]]; then echo "Unknown benchmark: $BENCH (only 'pawcare' supported)"; exit 2; fi
if [[ -z "${FIGMA_TOKEN:-}" ]]; then echo "BLOCKED: set FIGMA_TOKEN (never commit it) to refresh $FIXTURE"; exit 3; fi
echo "Refreshing $FIXTURE from Figma node 4:1049 ..."
curl -sf -H "X-Figma-Token: $FIGMA_TOKEN" \
  "https://api.figma.com/v1/files/CZOVzyzTyFfKOf4vZdrLTG/nodes?ids=4-1049" \
  -o "$FIXTURE/raw-4-1049.json"
echo "Saved raw-4-1049.json (review the diff; regenerate geometry/expected-*.json only from verified renders)."
