#!/bin/bash
# =========================================================
# デプロイ後の確認（本番 / ローカル）。
#   API: health(DB) → 返ってくるのが JSON か → 未ログインは 401 → 登録 → ログイン → /me → 試合・ブース・お気に入りの閲覧
#        → 一般ユーザーの書き込みは 403 → ログアウト → 失効したトークンは 401
#   フロント（第2引数を渡したとき）: CORS（許可した Origin だけ / プリフライトのキャッシュ）→ SPA の直接アクセス（/login, /games/1）
#        → PWA の manifest・Service Worker・アイコン → **ビルドされたフロントが、この API の URL を向いているか**
# 使い方: bash scripts/smoke-test.sh https://<railway-domain>/api [https://<vercel-domain>]
# 注意: 実行のたびに、確認用の一般ユーザー（smoke-<時刻>@example.com）を 1 件登録する（削除する API は無い）。
# 試合が 1 件も無い環境（デモデータ未投入）では、ブースの確認を飛ばす。
# 書き込み API は「登録したばかりの一般ユーザーが自分のトークンで直接叩くと 403」だけを確認する（本番のデータを変えないため）。
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
fail() { echo "✗ $1"; shift; for line in "$@"; do echo "  $line"; done; exit 1; }
# ヘッダー値（大文字小文字を区別しない。最初の 1 つ）
header_of() { printf '%s' "$1" | tr -d '\r' | grep -i "^$2:" | head -1 | sed -E "s/^[^:]+:[[:space:]]*//"; }
json() { python3 -c "import sys,json; print(json.load(sys.stdin)$1)"; }

case "$API" in
  */api) ;;
  *) fail "API の URL の末尾が /api ではありません: $API" "Railway の URL + /api を指定してください（例: https://xxxx.up.railway.app/api）" ;;
esac
case "$API" in
  https://*) ;;
  http://localhost*|http://127.0.0.1*) echo "- local API ($API): https のチェックは飛ばします" ;;
  *) fail "API の URL が https:// ではありません: $API" ;;
esac

# health が JSON で返る（HTML なら、Root Directory の設定ミスや別のサービスにつながっている）
hdr=$(curl -sS --max-time 30 -D - -o /dev/null "$API/health" -H 'Accept: application/json' | tr -d '\r')
case "$(header_of "$hdr" content-type)" in
  application/json*) ;;
  *) fail "health が JSON を返していません（Content-Type: $(header_of "$hdr" content-type)）" "Railway の web サービスの Root Directory が /backend か、PORT と公開ドメインのポートが一致しているかを確認してください" ;;
esac
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
[ "$(body "$out" | json "['data']['role']")" = "user" ] && echo "✓ a registered user has the user role"

# 一般ユーザーのトークンで書き込み API を直接呼ぶ → すべて 403（存在しない ID でも 403。役割の確認が ID の確認より先）
for spec in "PUT /staff/games/1/booths/1/congestion {\"level\":1}" "PUT /staff/games/1/menu-items/1/stock {\"status\":\"in_stock\"}" \
            "POST /staff/booths/1/menu-items {}" "PUT /staff/booths/1/menu-items/1 {}" "DELETE /staff/booths/1/menu-items/1 {}" \
            "GET /staff/booths {}" "POST /admin/booths/1/invitations {}" "GET /admin/booths/1/staff {}" "DELETE /admin/booths/1/staff/1 {}"; do
  read -r method path payload <<< "$spec"
  if [ "$method" = "GET" ]; then out=$(req "$API$path" "${AUTH[@]}"); else out=$(req -X "$method" "$API$path" "${AUTH[@]}" -d "$payload"); fi
  check "user is forbidden: $method $path" 403 "$out"
done

out=$(req "$API/me" -H 'Accept: application/json');                         check "protected route rejects anonymous" 401 "$out"
out=$(req "$API/games" -H 'Accept: application/json');                      check "games require login" 401 "$out"

out=$(req "$API/games" "${AUTH[@]}");                                       check "games list" 200 "$out"
GAME_ID=$(printf '%s' "$(body "$out")" | python3 -c "import sys,json; d=json.load(sys.stdin)['data']; print(d[0]['id'] if d else '')")
if [ -z "$GAME_ID" ]; then
  echo "- no games in this environment (seed the demo data to check booths); skipping booth checks"
else
  out=$(req "$API/games/$GAME_ID" "${AUTH[@]}");                            check "game detail" 200 "$out"
  out=$(req "$API/games/$GAME_ID/booths?sort=congestion" "${AUTH[@]}");     check "booth list" 200 "$out"
  BOOTH_ID=$(printf '%s' "$(body "$out")" | python3 -c "import sys,json; d=json.load(sys.stdin)['data']; print(d[0]['booth_id'] if d else '')")
  if [ -n "$BOOTH_ID" ]; then
    [ "$(body "$out" | json "['meta']['stale_after_minutes']")" = "15" ] && echo "✓ booth list returns the freshness threshold"
    out=$(req "$API/games/$GAME_ID/booths/$BOOTH_ID" "${AUTH[@]}");         check "booth detail" 200 "$out"
    out=$(req "$API/games/$GAME_ID/booths/$BOOTH_ID/congestion-history" "${AUTH[@]}"); check "congestion history" 200 "$out"
    out=$(req -X PUT "$API/booths/$BOOTH_ID/favorite" "${AUTH[@]}");        check "add favorite" 200 "$out"
    out=$(req "$API/games/$GAME_ID/booths?favorites=1" "${AUTH[@]}");       check "favorites filter" 200 "$out"
    [ "$(body "$out" | json "['data'][0]['booth_id']")" = "$BOOTH_ID" ] && echo "✓ favorites filter returns the favorited booth"
    out=$(req "$API/me/favorites" "${AUTH[@]}");                            check "favorites list" 200 "$out"
    out=$(req -X DELETE "$API/booths/$BOOTH_ID/favorite" "${AUTH[@]}");     check "remove favorite" 200 "$out"
  fi
fi
out=$(req -X POST "$API/auth/logout" "${AUTH[@]}");                         check "logout" 200 "$out"
out=$(req "$API/me" "${AUTH[@]}");                                          check "token is revoked after logout" 401 "$out"

out=$(req "$API/nothing-here" -H 'Accept: application/json');                check "unknown route returns a JSON 404" 404 "$out"
[ "$(body "$out" | json "['message']")" = "見つかりません" ] && echo "✓ the 404 body is the Laravel JSON error"

if [ -n "$FRONT" ]; then
  FRONT="${FRONT%/}"
  ORIGIN_OF_API="${API%/api}"

  # ----- CORS（ブラウザから API を呼べるか）-----
  # プリフライト。Origin は末尾スラッシュなし（ブラウザが送る形）。許可されていなければ CORS エラーになる
  hdr=$(curl -sS --max-time 30 -o /dev/null -D - -X OPTIONS "$API/me" -H "Origin: $FRONT" \
    -H 'Access-Control-Request-Method: GET' -H 'Access-Control-Request-Headers: authorization,content-type' | tr -d '\r')
  [ "$(header_of "$hdr" access-control-allow-origin)" = "$FRONT" ] \
    && echo "✓ CORS allows $FRONT" \
    || fail "CORS が $FRONT を許可していません" "Railway の CORS_ALLOWED_ORIGINS を Vercel の URL（末尾スラッシュなし、https://〜）にして、web サービスを再デプロイしてください" "$hdr"
  # プリフライトのキャッシュ（0 や無しだと、全リクエストの前に確認の通信が入り、弱い回線で遅くなる）
  max_age=$(header_of "$hdr" access-control-max-age)
  [ "${max_age:-0}" -ge 600 ] && echo "✓ CORS preflight is cacheable (max-age ${max_age}s)" || fail "プリフライトのキャッシュ（Access-Control-Max-Age）が短すぎます（${max_age:-なし}）"
  # 実際のリクエストにも Origin が返る
  hdr=$(curl -sS --max-time 30 -o /dev/null -D - "$API/health" -H "Origin: $FRONT" | tr -d '\r')
  [ "$(header_of "$hdr" access-control-allow-origin)" = "$FRONT" ] && echo "✓ CORS header is present on real requests" || fail "実際のリクエストに Access-Control-Allow-Origin が返りません" "$hdr"
  # 許可していない Origin には、その Origin を許可する応答を返さない（Laravel は、許可している Origin を固定で返す。
  # ブラウザは、要求した Origin と一致しない応答を拒否するので安全。危険なのは、* や相手の Origin をそのまま返す設定）
  hdr=$(curl -sS --max-time 30 -o /dev/null -D - -X OPTIONS "$API/me" -H 'Origin: https://evil.example' \
    -H 'Access-Control-Request-Method: GET' | tr -d '\r')
  allowed=$(header_of "$hdr" access-control-allow-origin)
  if [ "$allowed" = "*" ] || [ "$allowed" = "https://evil.example" ]; then
    fail "許可していない Origin（https://evil.example）まで CORS が許可しています" "CORS_ALLOWED_ORIGINS に * を入れていないか確認してください" "$hdr"
  fi
  echo "✓ CORS does not allow other origins"

  # ----- フロント（Vercel）-----
  page() { curl -sS --max-time 30 -o /tmp/smoke-page.$$ -w '%{http_code} %{content_type}' "$FRONT$1"; }
  for path in / /login /games/1 /favorites; do
    res=$(page "$path")
    case "$res" in
      "200 text/html"*) echo "✓ frontend serves $path (SPA rewrite)" ;;
      *) fail "フロントの $path が HTML の 200 を返しません（$res）" "frontend/vercel.json の rewrites と、Vercel の Root Directory = frontend を確認してください" ;;
    esac
  done
  # index.html から、ビルドされた JS を探して、API の URL を向いているか確認する
  curl -sS --max-time 30 "$FRONT/" -o /tmp/smoke-index.$$
  asset=$(grep -oE '/assets/index-[A-Za-z0-9_-]+\.js' /tmp/smoke-index.$$ | head -1)
  [ -n "$asset" ] || fail "index.html からビルドされた JS が見つかりません" "Vercel のビルドが成功しているか確認してください"
  curl -sS --max-time 60 "$FRONT$asset" -o /tmp/smoke-bundle.$$
  if grep -qF "$API" /tmp/smoke-bundle.$$; then
    echo "✓ the built frontend points to this API ($API)"
  else
    fail "ビルドされたフロントが、この API の URL を向いていません" \
      "Vercel の VITE_API_BASE_URL を $API にして、**再デプロイ**してください（VITE_ の変数は、ビルド時に埋め込まれるため、変更しただけでは反映されません）" \
      "（開発用の既定値 http://localhost/api が埋め込まれていないか: $(grep -cF 'http://localhost/api' /tmp/smoke-bundle.$$ || true) 件）"
  fi
  rm -f /tmp/smoke-index.$$ /tmp/smoke-bundle.$$ /tmp/smoke-page.$$

  # ----- PWA -----
  res=$(curl -sS --max-time 30 -o /tmp/smoke-manifest.$$ -w '%{http_code}' "$FRONT/manifest.webmanifest")
  [ "$res" = "200" ] && python3 -c "import json; m=json.load(open('/tmp/smoke-manifest.$$')); assert m['name']=='CourtQ' and m['display']=='standalone', m" \
    && echo "✓ PWA manifest (CourtQ, standalone)" || fail "manifest.webmanifest が正しくありません（$res）"
  rm -f /tmp/smoke-manifest.$$
  hdr=$(curl -sS --max-time 30 -o /dev/null -D - "$FRONT/sw.js" | tr -d '\r')
  echo "$hdr" | head -1 | grep -q " 200" || fail "sw.js（Service Worker）が 200 を返しません"
  case "$(header_of "$hdr" content-type)" in *javascript*) ;; *) fail "sw.js の Content-Type が JavaScript ではありません（$(header_of "$hdr" content-type)）" ;; esac
  case "$(header_of "$hdr" cache-control)" in *immutable*|*max-age=[1-9][0-9][0-9][0-9][0-9]*) fail "sw.js が長くキャッシュされる設定です（$(header_of "$hdr" cache-control)）" "更新が端末に届かなくなります。frontend/vercel.json の headers を確認してください" ;; esac
  echo "✓ service worker is served and not cached for long"
  res=$(curl -sS --max-time 30 -o /dev/null -w '%{http_code} %{content_type}' "$FRONT/icons/icon-192.png")
  case "$res" in "200 image/png"*) echo "✓ PWA icon" ;; *) fail "icons/icon-192.png が画像で返りません（$res）" ;; esac
fi

echo "All smoke checks passed."
