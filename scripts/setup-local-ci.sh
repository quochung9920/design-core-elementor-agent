#!/usr/bin/env bash
# First-time Local CI setup helper. Checks dependencies, never installs Docker.
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

check() { if command -v "$1" >/dev/null 2>&1; then echo "  $1  OK ($($1 --version 2>&1 | head -n1))"; else echo "  $1  MISSING — $2"; fi }

echo "Design Core Local CI — setup check"
echo "  php:            $(command -v php >/dev/null && php -v | head -n1 || echo 'not in PATH (wordpress-dev runtime php8.5 also works)')"
check node "install Node 20+: https://nodejs.org"
check npm  "ships with Node"
check git  "install git"
check curl "install curl"
if command -v docker >/dev/null 2>&1; then echo "  docker  OK ($(docker --version))"; else echo "  docker  MISSING — PHP 8.1/8.2/8.3 matrix needs Docker (https://docs.docker.com/engine/install/). Without it ci-local.sh reports BLOCKED."; fi
echo ""
echo "Project setup:"
echo "  npm ci --ignore-scripts --no-audit --no-fund"
echo "  npx playwright install chromium   (only if scripts/browser-probe.mjs fails)"
echo ""
echo "Then run:  ./scripts/ci-local.sh"
