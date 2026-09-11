#!/usr/bin/env bash
# Design Core Local CI — authoritative WSL validation workflow.
# Full mode:  ./scripts/ci-local.sh
# Fast mode:  ./scripts/ci-local.sh --fast   (syntax + contracts + node checks only)
set -Eeuo pipefail

FAST=0
if [[ "${1:-}" == "--fast" ]]; then FAST=1; fi

# Repository root, independent of CWD.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
ARTIFACTS="$ROOT/.artifacts/local-ci"
mkdir -p "$ARTIFACTS"

# wordpress-dev runtime needs its libs (libargon2 etc.) — never rely on host ldconfig.
export LD_LIBRARY_PATH="${LD_LIBRARY_PATH:-}"
set +u
if [[ -f /home/dev/wordpress-dev/env.sh ]]; then
  # shellcheck disable=SC1091
  source /home/dev/wordpress-dev/env.sh >/dev/null 2>&1 || true
fi
set -u
export LD_LIBRARY_PATH="/home/dev/wordpress-dev/runtime/usr/lib/x86_64-linux-gnu:/home/dev/wordpress-dev/runtime/lib/x86_64-linux-gnu:/home/dev/wordpress-dev/runtime/usr/lib:${LD_LIBRARY_PATH:-}"
export PATH="/home/dev/wordpress-dev/runtime/usr/bin:$PATH"

PASS=0; FAIL=0; BLOCKED=0
FAILED_STAGES=()
declare -A DUR

say()  { printf '%s\n' "$*"; }
stage() { printf '\n  %-24s ' "$1"; }
ok()   { printf 'PASS\n'; PASS=$((PASS+1)); }
bad()  { printf 'FAIL (%s)\n' "$1"; FAIL=$((FAIL+1)); FAILED_STAGES+=("$2"); }
blk()  { printf 'BLOCKED (%s)\n' "$1"; BLOCKED=$((BLOCKED+1)); FAILED_STAGES+=("$2"); }
timed() { local s=$SECONDS; "$@" ; DUR["$1"]=$((SECONDS-s)); }

say "=================================================="
say " Design Core Local CI $([[ $FAST == 1 ]] && echo '(fast)' || echo '(full)')"
say "=================================================="
say "Root: $ROOT"

# ---- Environment -----------------------------------------------------------
say ""
say "Environment"
if grep -qi microsoft /proc/version 2>/dev/null; then stage "WSL"; ok; else stage "WSL"; say "non-WSL (ok)"; ok; fi

stage "PHP";        if [[ -x /home/dev/wordpress-dev/runtime/usr/bin/php8.5 ]]; then PHP_BIN=/home/dev/wordpress-dev/runtime/usr/bin/php8.5; ok; elif PHP_REAL="$(type -P php 2>/dev/null)" && [[ -n "$PHP_REAL" ]]; then PHP_BIN="$PHP_REAL"; ok; elif command -v php >/dev/null 2>&1; then PHP_BIN=php; ok; else blk "no php in PATH"; fi
stage "Node";       if command -v node >/dev/null 2>&1; then ok; else blk "node missing"; fi
stage "npm";        if command -v npm  >/dev/null 2>&1; then ok; else blk "npm missing"; fi
stage "Docker";     if command -v docker >/dev/null 2>&1; then ok; else say "absent (PHP matrix will BLOCK)"; BLOCKED=$((BLOCKED+1)); fi
stage "Playwright"; if node scripts/browser-probe.mjs >/dev/null 2>&1; then ok; else bad "chromium missing (run ./scripts/setup-local-ci.sh)" "playwright"; fi

PHP_BIN="${PHP_BIN:-php}"

# ---- PHP matrix (8.1 / 8.2 / 8.3 via Docker) ---------------------------------
say ""
say "PHP matrix"
# Docker is the only honest 8.1/8.2/8.3 matrix. Without Docker we BLOCK, never fake PASS.
if command -v docker >/dev/null 2>&1; then
  for V in 8.1 8.2 8.3; do
    stage "PHP $V"
    if docker build -q --build-arg "PHP_VERSION=$V" -f docker/ci/php/Dockerfile . >"$ARTIFACTS/php-$V-image.txt" 2>&1 \
       && docker run --rm -v "$ROOT:/repo:ro" "$(cat "$ARTIFACTS/php-$V-image.txt")" php -l design-core-elementor.php >"$ARTIFACTS/php-$V-lint.txt" 2>&1; then ok; else bad "see $ARTIFACTS/php-$V-*.txt" "php-$V"; fi
  done
else
  for V in 8.1 8.2 8.3; do stage "PHP $V"; blk "docker unavailable — matrix cannot run" "php-matrix"; done
fi

# ---- PHP syntax ---------------------------------------------------------------
say ""
say "Syntax"
stage "php -l (all files)"
if find . -name '*.php' -not -path './vendor/*' -not -path './node_modules/*' -print0 | xargs -0 -n1 "$PHP_BIN" -l >"$ARTIFACTS/syntax.txt" 2>&1; then ok; else bad "see $ARTIFACTS/syntax.txt" "syntax"; fi

# ---- Core contracts ------------------------------------------------------------
say ""
say "Contracts"
CONTRACTS="architecture registry native-fidelity strict-native responsive-compiler design-intelligence design-brain design-memory rc19-intelligence rc20-fidelity reference-integration fixed-width-governance figma-fidelity pawcare-benchmark"
for T in $CONTRACTS; do
  stage "$T"
  if [[ "$T" == "figma-fidelity" ]]; then
    if "$PHP_BIN" tests/figma-fidelity/run.php >"$ARTIFACTS/contract-$T.txt" 2>&1 \
       && "$PHP_BIN" tests/figma-fidelity/adapter.php >>"$ARTIFACTS/contract-$T.txt" 2>&1; then ok; else bad "see $ARTIFACTS/contract-$T.txt" "contract-$T"; fi
  elif "$PHP_BIN" "tests/$T/run.php" >"$ARTIFACTS/contract-$T.txt" 2>&1; then ok; else bad "see $ARTIFACTS/contract-$T.txt" "contract-$T"; fi
done

if [[ $FAST == 1 ]]; then
  say ""
  say "=================================================="
  if [[ $FAIL == 0 && $BLOCKED == 0 ]]; then say " LOCAL CI FAST PASS"; else say " LOCAL CI FAST FAIL: ${FAILED_STAGES[*]}"; exit 1; fi
  say "=================================================="
  exit 0
fi

# ---- Node -----------------------------------------------------------------------
say ""
say "Node"
stage "npm ci"
if npm ci --ignore-scripts --no-audit --no-fund >"$ARTIFACTS/npm-ci.txt" 2>&1; then ok; else bad "see $ARTIFACTS/npm-ci.txt" "npm-ci"; fi
stage "npm run check"
if npm run check >"$ARTIFACTS/npm-check.txt" 2>&1; then ok; else bad "see $ARTIFACTS/npm-check.txt" "npm-check"; fi

# ---- Browser: multi-viewport ------------------------------------------------------
say ""
say "Browser"
stage "viewports 1440/1024/768/390"
if node scripts/browser-analyze-target.mjs "$ROOT/tests/fixtures/benchmark-hero-split.html" 1440,1024,768,390 >"$ARTIFACTS/viewports.json" 2>"$ARTIFACTS/viewports.err"; then
  if node -e "const x=require('$ARTIFACTS/viewports.json'); for (const w of ['1440','1024','768','390']) { if (!x.viewports || !x.viewports[w] || !x.viewports[w].length) process.exit(1); }" 2>>"$ARTIFACTS/viewports.err"; then ok; else bad "incomplete evidence" "viewports"; fi
else bad "browser crashed, see $ARTIFACTS/viewports.err" "viewports"; fi

# ---- Responsive: no destructive overflow at mobile ---------------------------------
stage "responsive 390/768"
RESP_OK=1
for W in 390 768; do
  if ! node scripts/capture-page.mjs "$ROOT/tests/fixtures/benchmark-hero-split.html" "$W" "$ARTIFACTS/resp-$W.png" >"$ARTIFACTS/resp-$W.json" 2>&1; then RESP_OK=0; fi
  if ! node -e "const x=require('$ARTIFACTS/resp-$W.json'); if (x.horizontal_overflow) process.exit(1);" 2>/dev/null; then RESP_OK=0; fi
done
if [[ $RESP_OK == 1 ]]; then ok; else bad "overflow or capture failure" "responsive"; fi

# ---- Screenshot regression (deterministic engine check) ------------------------------
say ""
say "Visual"
stage "screenshot engine"
if node scripts/capture-page.mjs "$ROOT/tests/fixtures/marketing.html" 390 "$ARTIFACTS/ref.png" >"$ARTIFACTS/shot-ref.json" 2>&1 \
   && node scripts/capture-page.mjs "$ROOT/tests/fixtures/marketing.html" 390 "$ARTIFACTS/cand.png" >"$ARTIFACTS/shot-cand.json" 2>&1 \
   && node scripts/visual-compare.mjs "$ARTIFACTS/ref.png" "$ARTIFACTS/cand.png" >"$ARTIFACTS/visual.json" 2>&1 \
   && node -e "const x=require('$ARTIFACTS/visual.json'); if(!x.comparable || x.similarity < 0.999) process.exit(1);"; then
  SIM=$(node -p "require('$ARTIFACTS/visual.json').similarity"); printf 'PASS (similarity %s)\n' "$SIM"; PASS=$((PASS+1))
else bad "see $ARTIFACTS/visual.json" "screenshot-engine"; fi

stage "PawCare benchmark"
if "$PHP_BIN" tests/pawcare-benchmark/run.php >"$ARTIFACTS/pawcare.txt" 2>&1; then
  PAW_SCORE=$(grep -o '[0-9]* assertions, 0 failures' "$ARTIFACTS/pawcare.txt" | head -n1 || true)
  printf 'PASS (%s)\n' "$PAW_SCORE"; PASS=$((PASS+1))
else bad "see $ARTIFACTS/pawcare.txt" "pawcare"; fi

# ---- Summary -------------------------------------------------------------------------
say ""
say "=================================================="
if [[ $FAIL == 0 && $BLOCKED == 0 ]]; then
  say " LOCAL CI PASS ($PASS stages)"
  say "=================================================="
  exit 0
else
  say " LOCAL CI FAIL — pass=$PASS fail=$FAIL blocked=$BLOCKED"
  say " Failed: ${FAILED_STAGES[*]}"
  say "=================================================="
  exit 1
fi
