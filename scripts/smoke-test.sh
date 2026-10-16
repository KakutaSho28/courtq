#!/bin/bash
# =========================================================
# 本番/ローカル API スモークテスト
# health(DB) → 登録 → ログイン → /me → ログアウト → 失効したトークンは 401
# 使い方: bash scripts/smoke-test.sh https://<railway-domain>/api [https://<vercel-domain>]
# 第2引数（任意）を渡すと、CORS プリフライトとフロントのSPAルーティングも確認する。
# 試合・ブース・書き込み API の確認は、対応する WP で追加する。
# =========================================================
set -euo pipefail

API="${1:?usage: smoke-test.sh <api-base-url> [frontend-url]}"
FRONT="${2:-}"
API="${API%/}"
EMAIL="smoke-$(date +%s)-$RANDOM@example.com"
PASS="password123"

req() { curl -sS --max-time 30 -w '\n%{http_code}' "$@"; }
check() { # name expected_status body_and_status
  local name="$1" expected="$2" out="$3"
  local status="${out##*$'\n'}"
  if [ "$status" != "$expected" ]; then
    echo "✗ $name (expected $expected, got $status)"; echo "${out%$'\n'*}"; exit 1
  fi
  echo "✓ $name ($status)"
}
body() { printf '%s' "${1%$'\n'*}"; }
json() { python3 -c "import sys,json; print(json.load(sys.stdin)$1)"; }

out=$(req "$API/health" -H 'Accept: application/json');                     check "health (database ok)" 200 "$out"
[ "$(body "$out" | json "['data']['database']")" = "ok" ] && echo "✓ health reports database ok"

out=$(req -X POST "$API/auth/register" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d "{\"name\":\"Smoke\",\"email\":\"$EMAIL\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\"}"); check "register" 201 "$out"
out=$(req -X POST "$API/auth/login" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}");                       check "login" 200 "$out"
TOKEN=$(body "$out" | json "['data']['token']")
AUTH=(-H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json')

out=$(req "$API/me" "${AUTH[@]}");                                          check "me" 200 "$out"
[ "$(body "$out" | json "['data']['email']")" = "$EMAIL" ] && echo "✓ me returns the registered user"

out=$(req "$API/me" -H 'Accept: application/json');                         check "protected route rejects anonymous" 401 "$out"
out=$(req -X POST "$API/auth/logout" "${AUTH[@]}");                         check "logout" 200 "$out"
out=$(req "$API/me" "${AUTH[@]}");                                          check "token is revoked after logout" 401 "$out"

if [ -n "$FRONT" ]; then
  FRONT="${FRONT%/}"
  hdr=$(curl -sS -o /dev/null -D - -X OPTIONS "$API/me" -H "Origin: $FRONT" \
    -H 'Access-Control-Request-Method: GET' -H 'Access-Control-Request-Headers: authorization,content-type' | tr -d '\r')
  echo "$hdr" | grep -qi "^access-control-allow-origin: $FRONT" && echo "✓ CORS allows $FRONT" || { echo "✗ CORS does not allow $FRONT"; echo "$hdr"; exit 1; }
  code=$(curl -sS -o /dev/null -w '%{http_code}' "$FRONT/login")
  [ "$code" = "200" ] && echo "✓ frontend serves /login (SPA rewrite)" || { echo "✗ frontend /login returned $code"; exit 1; }
fi

echo "All smoke checks passed."
