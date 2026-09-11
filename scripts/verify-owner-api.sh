#!/usr/bin/env bash
set -euo pipefail

: "${DESIGN_CORE_API_URL:?Set DESIGN_CORE_API_URL, e.g. https://api.designcorehub.online/v1}"
: "${DESIGN_CORE_API_TOKEN:?Set DESIGN_CORE_API_TOKEN to the dcapi credential without printing it}"

BASE="${DESIGN_CORE_API_URL%/}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

request() {
  local name="$1" method="$2" path="$3" auth="$4" data="${5:-}"
  local out="$TMP/$name.json"
  local args=(-sS -o "$out" -w '%{http_code}' -X "$method" -H 'Accept: application/json')
  if [[ "$auth" == "yes" ]]; then args+=(-H "Authorization: Bearer $DESIGN_CORE_API_TOKEN"); fi
  if [[ -n "$data" ]]; then args+=(-H 'Content-Type: application/json' --data "$data"); fi
  local code
  code="$(curl "${args[@]}" "$BASE$path")"
  printf '%-24s HTTP %s\n' "$name" "$code"
  if [[ "$code" != "200" ]]; then
    cat "$out" >&2 || true
    echo >&2
    exit 1
  fi
}

request openapi GET /openapi no
request manifest GET /manifest yes
request site_status GET /site/status yes
request understand POST /understand yes '{"brief":"Verify the Design Core owner API read-only path.","page_id":0,"site_map_limit":25}'

php -r '
$files = array_slice($argv,1);
foreach($files as $f){$x=json_decode(file_get_contents($f),true);if(!is_array($x)){fwrite(STDERR,"invalid-json:$f\n");exit(1);}}
' "$TMP/openapi.json" "$TMP/manifest.json" "$TMP/site_status.json" "$TMP/understand.json"

php -r '
$o=json_decode(file_get_contents($argv[1]),true);
$m=json_decode(file_get_contents($argv[2]),true);
$u=json_decode(file_get_contents($argv[3]),true);
if(($o["openapi"]??"")!=="3.1.0") exit(1);
if(($m["mcp_required"]??true)!==false) exit(1);
if((int)($m["operation_count"]??0)!==42) exit(1);
if(($u["mutation"]??null)!==false) exit(1);
echo "Owner API contract: PASS\n";
' "$TMP/openapi.json" "$TMP/manifest.json" "$TMP/understand.json"

printf 'Token value was not printed. Read-only verification complete.\n'
