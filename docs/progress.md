# 進捗ログ

新しいセッションで再開するときは、まずこのファイルと `claude-code-booth-prompt.md`・`CLAUDE.md` を読む。
各 WP の最後に更新する（完了 / 次 / 決定事項 / 未解決の質問）。

## 現在地

| WP | 状態 | ブランチ / PR |
|---|---|---|
| WP-B0 計画 | ✅ 完了（2026-10-16 にユーザーが承認） | [#4](https://github.com/KakutaSho28/courtq/pull/4)（`develop` 向け） |
| WP-B1 基盤 | ✅ 完了（別の作業で実装済み。2026-10-16 に検証して完了扱いにした） | [#1](https://github.com/KakutaSho28/courtq/pull/1)・[#2（Laravel 12）](https://github.com/KakutaSho28/courtq/pull/2)、`develop` にマージ済み |
| WP-B2 データモデル | ✅ 完了（`develop` にマージ済み） | [#3](https://github.com/KakutaSho28/courtq/pull/3) |
| WP-B3 読み取り API | ✅ 完了（`develop` にマージ済み） | [#5](https://github.com/KakutaSho28/courtq/pull/5) |
| WP-B4 ロール・認可・スタッフ API | 🔄 作業中（認可ルールを先に確定 → 実装 → テスト） | `feature/wp-b4-roles-staff-api` → `develop` |
| WP-B5〜B10 | ⬜ 未着手 | — |

## 完了

### WP-B0（2026-10-16）

- `claude-code-booth-prompt.md` と Spovie（`KakutaSho28/spovie` の `main` / `89e6bfb`、読み取り専用で clone）を読み、ファイルごとの計画を [`wp-b0-plan.md`](wp-b0-plan.md) にまとめた。
- 実行環境を確認: PHP 8.3.6 / Composer / Node 22 / Playwright の Chromium はあり。**Docker デーモンは無い**（`docker compose` 系は未検証として扱う）。
- 実行したテスト・ビルド: なし（コードがまだ無いため）。

## 次にやること

1. WP-B4（作業中。下の「WP-B4 の認可ルール」を実装・テストする）: ロール（`EnsureRole`）・Policy・STAFF API・招待コード・履歴・throttle。「一般ユーザーが書き込み API を直接叩くと 403」を全書き込み API でテストする。B3 で用意した `BoothService::forgetList()` を、混雑・在庫の更新から呼ぶ。`/me` に `role` と担当ブースを追加する。招待コードは紛らわしい文字を除いた 8 文字。

### WP-B1 の検証（`develop` @ `7982918`、2026-10-16）

| 確認 | 結果 |
|---|---|
| `php artisan test` | ✅ 22 件成功（150 アサーション） |
| `php artisan route:list` | ✅ 6 ルート（health / register / login / logout / me / csrf-cookie）。競合なし |
| `php artisan docs:api` | ✅ 実行しても `docs/api-endpoints.md` に差分なし |
| `npm run build`（tsc strict + vite + PWA） | ✅ 成功 |
| Playwright e2e | ✅ 13 件成功（auth 4 / pwa 6 / realtime 3）。※ 下の注記 |
| スモークテスト（`php artisan serve` + sqlite） | ✅ 全項目成功（health / 登録 / ログイン / me / 未認証 401 / ログアウト / ログアウト後の 401） |
| Docker（`docker compose up`・`setup-backend.sh`・`backend/Dockerfile` のビルド） | **not verified**（このセッションに Docker デーモンが無い） |
| MySQL 8 での migrate | **not verified**（MySQL が無い。テストは sqlite） |
| GitHub Actions（CI） | このセッションでは確認していない |
| Railway / Vercel / Pusher の実環境 | **not verified**（ダッシュボード操作が必要） |

e2e の注記: このセッションの Playwright（1.4x）は `/opt/pw-browsers` に入っている Chromium（1194）と版が合わず、そのままでは起動できなかった。コミットしない一時設定で `chromium_headless_shell-1194` を指定して実行した。リポジトリの設定は変えていない。CI は `npx playwright install` で合う版を入れるため影響しない。

**作業指示・計画書との差の一覧**（確認した項目）:

| 項目 | 結果 |
|---|---|
| 日本語の 401 / 404 / 422 / 429 | ✅ 実装済み・テスト済み（401 は「ログインが必要です」。計画書の文言と違う） |
| `GET /api/me`（AUTH-04） | ✅ 実装済み（id / name / email。role は B2 の後、担当ブースは B4） |
| 日本語のバリデーションメッセージ | ✅ `lang/ja/validation.php`、`locale=ja` |
| ログインの throttle | ✅ `throttle:login`（10 回/分）、登録は `throttle:register`。テスト済み |
| `frontend/src/constants.ts` | ✅ `APP_NAME` / localStorage キー / `API_CACHE_NAME` |
| 公開チャンネル `game.{id}` の下地 | ✅ `echo.ts` は `echo.channel()`、`/api/broadcasting/auth` は無し、`channels.php` は空。e2e で「認証 API が呼ばれない」ことを確認。イベント自体は B8 |
| `AuthService` に集約・外部 ID の拡張方針 | ✅（B2 でコメント・カラムを追加） |
| FFmpeg / S3 / fabric / `doctrine/dbal` / Spovie の名前 | ✅ 残っていない（`config/filesystems.php` の S3 ディスク設定のみ残存。未使用なので放置） |
| CI | ✅ backend test + `route:list` / frontend build / e2e |
| `main` と `develop` | ✅ 両方ある |

→ **足りないものは無かったので、B1 の追加修正 PR は作っていない。**

### WP-B2（PR #3）のレビュー・検証

| 確認 | 結果 |
|---|---|
| `php artisan test` | ✅ 48 件成功（250 アサーション）。修正前後とも |
| テーブル・カラム名・型・一意制約を作業指示 3 章と照合 | ✅ 11 テーブルすべて一致（`item_stocks` の `updated_at` は `timestamps()` で表現） |
| `role` を `$fillable` に入れない | ✅ |
| デモデータが架空（B.LEAGUE 等の名称を使わない） | ✅ テストで検証されている |
| MySQL 8 での migrate | **not verified** |

見つけて直したこと（PR #3 に追加コミット）:

1. 作成済みの `users` マイグレーションを書き換えていた → 元に戻し、別のマイグレーション（`2026_10_16_100000_add_role_and_provider_to_users_table.php`）にした。すでに migrate 済みの DB にも列が入る。
2. 招待コードの長さが 10 文字（設定・Factory）だった → 承認済みの 8 文字に合わせた。紛らわしい文字を除く生成は B4。
3. `games` の一意制約は `section` が NULL だと MySQL では重複を防げない → ER 図に注意書き。CSV 取り込み（B7）でアプリ側でも確認する。

## 決定事項

- Spovie は読み取り専用の参考。Spovie の業務ドメイン（動画・アノテーション・クリップ・共有・コメント・チーム・S3/R2）は持ち込まない。
- 作業指示が「Spovie にある」と想定していたが実際には無いもの（日本語の 401/404/429、`/me`、日本語バリデーション、ログインの throttle、`constants.ts`）は B1 で新規作成する。
- **Laravel 12 を採用**（Laravel 10 は修正版が出ない脆弱性が残るため。PR #2）。作業指示・計画書の Laravel 10 の記述は Spovie の話として残し、CourtQ は 12 と明記した。詳細は `wp-b0-plan.md` 8 章。
- B1 は別の作業で完了済みなので、再実装せず検証のみ行った（2026-10-16）。
- コミットメッセージに AI の共同作成者表記は入れない（CLAUDE.md の規約）。
- リアルタイムは公開チャンネル `game.{gameId}` のみ。`/api/broadcasting/auth` は作らない（Spovie の private チャンネル用の認証処理は削除）。

### WP-B3（2026-10-16）

読み取り API を service-first（Service とテスト → Controller / Route / Resource）で追加した。

| ID | API | 要点 |
|---|---|---|
| GAME-01 | `GET /games?date=&from=` | 今日の試合が先頭、あとは開始時刻順。省略時は今日以降。最大 100 件 |
| GAME-02 | `GET /games/{game}` | |
| BOOTH-01 | `GET /games/{game}/booths?category=&sort=&in_stock=&favorites=` | 軽い応答。共通部分を 20 秒キャッシュ（`COURTQ_LIST_CACHE_SECONDS`）。`forgetList()` で破棄 |
| BOOTH-02 | `GET /games/{game}/booths/{booth}` | 販売中のメニュー + その試合の在庫 + 最終更新。出店していないブースは 404 |
| BOOTH-03 | `GET /games/{game}/booths/{booth}/congestion-history` | 古い順で直近 200 件。更新者は返さない |
| FAV-01〜03 | `GET /me/favorites`、`PUT` / `DELETE /booths/{booth}/favorite` | PUT / DELETE は冪等。自分の分だけ |

実行したもの（このセッション）:

| 確認 | 結果 |
|---|---|
| `php artisan test` | ✅ 103 件成功（B2 までの 48 件 → 追加 55 件。Service 27 / API 3 ファイル / 契約テスト / 日本語 JSON） |
| `php artisan route:list` | ✅ 13 ルート。競合なし、クロージャなし（`DeploymentConfigTest`） |
| `php artisan docs:api` | ✅ 再生成してコミット（`ApiDocsTest` / `ApiContractTest` 成功） |
| `npm run build` | ✅ フロントは未変更だが規約どおり実行 |
| スモークテスト（`php artisan serve` + sqlite） | ✅ デモデータ入りと空の DB の両方で成功（閲覧系 + お気に入りを追加） |
| 実データでの目視（デモ Seeder → API） | ✅ 並び順・鮮度（20 分前の更新が `is_stale: true`）・閉店中は最後・日本語の 422 |
| MySQL 8 での実行 / Docker | **not verified**（MySQL / Docker が無い。CASE 式の並び替えと `join` は標準 SQL で書いたが、MySQL 上では未実行） |

決めたこと:

- **キャッシュするのは全員共通の部分だけ**（ブース・混雑・在庫の有無）。お気に入りの印と絞り込みはキャッシュの外で付ける（ユーザーごとに違うため。テストで「alice のお気に入りが bob に漏れない」を確認）。キャッシュは 1 試合 1 キー（`database` ドライバはタグ非対応）。
- **`is_stale` はキャッシュに入れず、応答のたびに計算**する（時間とともに変わるため）。しきい値は `config('courtq.stale_after_minutes')` の 1 か所。`meta.stale_after_minutes` も返す（オフラインの PWA が画面側で計算し直せるように）。一度も更新されていないブースは `level` などが null で `is_stale` は false。
- `category=food` / `merch` は兼営（`both`）のブースも含む。`in_stock=1` は「販売中のメニューに、売り切れでない在庫が 1 つ以上ある」ブース。
- 名前順は文字コード順（五十音順ではない）。ブース数が少ないので許容。必要になったら B5 で見直す。
- **API の JSON は日本語をエスケープしない**（`UnescapedJsonResponse`）。エスケープすると日本語が 1 文字 3 バイト → 6 バイトになり、会場の弱い回線で読む一覧が約 2 倍になるため。作業指示 1 章 10 の「軽いペイロード」のための追加。
- 履歴の `created_at` は `$fillable` に入れていない。B4 の更新処理は `now()` で記録する（テストでは `forceFill` で時刻を指定）。

### WP-B4 の認可ルール（実装前に確定。2026-10-16）

実装とテストはこの表を正とする。表と実装が食い違ったら、表を直してから実装を直す。

**登場人物**

| 呼び方 | 意味 |
|---|---|
| 未ログイン | トークンなし / 無効 |
| user | `role=user`（一般の来場者） |
| staff A | `role=staff` で、ブース A だけを担当（`booth_staff` に A） |
| staff B | `role=staff` で、ブース B だけを担当 |
| 担当なし staff | `role=staff` だが担当ブースが 0 件（解除された直後など） |
| admin | `role=admin`（`booth_staff` に関係なく全ブース） |

**検査の順番**（すべての書き込み API で同じ。前の段で止まったら後ろは評価しない）

1. `auth:sanctum` → 未ログインは **401**
2. `role:staff,admin` / `role:admin`（`EnsureRole`）→ 役割が足りなければ **403**。**ID の存在確認より前**に評価する（user が存在しない ID を送っても 404 ではなく 403。ID の有無を探れないようにするため）
3. `throttle`（STAFF-02 / 03 は 1 ユーザー 30 回/分、STAFF-05 は 1 ユーザー 10 回/分）→ **429**
4. ルートの ID の解決 → 存在しなければ **404**。メニューは `scopeBindings` で「そのブースのメニュー」に限って解決する（ブース A の URL にブース B のメニュー ID を入れても 404）
5. Policy（`BoothPolicy@manage`: admin、または `booth_staff` に含まれる）→ **403**。ルートの `can` ミドルウェアで評価し、**入力検証より前**にする（他人のブースへの不正な入力は 422 ではなく 403）
6. FormRequest → **422**
7. 業務ルール（例: そのブースがその試合に出店していない → 404、招待コードが無効 → 422）

**エンドポイントごとのルール**（○ = 成功、403 / 401 = 拒否）

| ID | メソッド・パス | 未ログイン | user | 担当なし staff | staff A（自分のブース A） | staff A → ブース B | admin |
|---|---|---|---|---|---|---|---|
| AUTH-04 | `GET /me`（`role` と担当ブースを追加） | 401 | ○ | ○ | ○ | – | ○ |
| STAFF-01 | `GET /staff/booths` | 401 | 403 | ○（0 件） | ○（A だけ） | B は含まれない | ○（全ブース） |
| STAFF-02 | `PUT /staff/games/{game}/booths/{booth}/congestion` | 401 | 403 | 403 | ○ | **403** | ○ |
| STAFF-03 | `PUT /staff/games/{game}/menu-items/{item}/stock` | 401 | 403 | 403 | ○（A のメニュー） | **403**（B のメニュー） | ○ |
| STAFF-04 | `POST /staff/booths/{booth}/menu-items` | 401 | 403 | 403 | ○ | **403** | ○ |
| STAFF-04 | `PUT /staff/booths/{booth}/menu-items/{item}` | 401 | 403 | 403 | ○ | **403**（URL も B）/ **404**（URL は A、メニューは B） | ○ |
| STAFF-04 | `DELETE /staff/booths/{booth}/menu-items/{item}` | 401 | 403 | 403 | ○ | **403** / **404**（同上） | ○ |
| STAFF-05 | `POST /staff/invitations/accept` | 401 | ○ | ○ | ○ | – | ○ |
| ADMIN-05 | `POST /admin/booths/{booth}/invitations` | 401 | 403 | 403 | 403 | 403 | ○ |
| ADMIN-06 | `GET /admin/booths/{booth}/staff` | 401 | 403 | 403 | 403 | 403 | ○ |
| ADMIN-06 | `DELETE /admin/booths/{booth}/staff/{user}` | 401 | 403 | 403 | 403 | 403 | ○ |

- 閲覧系（GAME / BOOTH / FAV）は B3 のまま、ログインしていれば全員 ○。
- ADMIN-01〜04（試合・ブースの作成/編集、CSV、試合へのブース割り当て）は管理画面と一緒に WP-B7 で作る。B4 は招待とスタッフ管理（ADMIN-05 / 06）だけ。

**業務ルール**

- 混雑（STAFF-02）: `level` 1〜4 必須、`wait_minutes` 0〜180 の整数または null。更新すると `game_booths` の現在値・`congestion_updated_at`・`congestion_updated_by` を書き換え、`congestion_logs` に 1 行追加する。そのブースがその試合に出店していなければ 404。
- 在庫（STAFF-03）: `status` は `in_stock` / `low` / `sold_out` 必須、`quantity` は 0 以上の整数または null。`item_stocks` を作成または更新し（試合 × メニューで 1 行）、`stock_logs` に 1 行追加する。そのメニューのブースがその試合に出店していなければ 404。
- 混雑・在庫・メニューを変えたら、ブース一覧のキャッシュを捨てる（`BoothService::forgetList()`）。メニューの変更は、そのブースが出店しているすべての試合の一覧を捨てる。
- メニュー（STAFF-04）: `name` / `kind`（food / merch）/ `price`（0 以上の整数・円）/ `description` / `is_active`。**在庫の記録があるメニューは削除できない**（422。削除すると在庫の履歴も消えるため）。販売をやめるときは `is_active=false` にする。
- 招待コード（ADMIN-05 / STAFF-05）: 紛らわしい文字（0 / O / 1 / I / L）を除いた英数字 8 文字。表示は `K7Q2-MX9P`（受け付けるときは大文字化し、ハイフン・空白を無視する）。有効期限は既定 7 日、1 回限り。使うと、`user` は `staff` になり、そのブースの担当になる（`admin` は役割を変えずに担当に加える。`staff` は担当ブースが増える）。無効・期限切れ・使用済みは**同じ 422 メッセージ**（どれに当たったかを推測させないため）。
- スタッフ解除（ADMIN-06）: `booth_staff` から外す。担当ブースが 0 件になった `staff` は `user` に戻す（使わない権限を残さないため）。`admin` の役割は変えない。担当していないユーザーを指定したら 404。
- 役割はサーバーだけが決める。どの API もリクエストの `role` を読まない（送っても無視されることをテストで確認する）。

**テストで必ず確認すること**

- 全書き込み API（STAFF-02〜04、ADMIN-05 / 06）で: 未ログイン 401 / user 403 / 担当なし staff 403 / staff A → ブース B 403 / 自分のブース ○ / admin ○。
- 拒否されたとき、DB が変わっていない（現在値・履歴・メニュー・担当が増えていない）。
- user が存在しない ID を送っても 403（404 ではない）。staff A がブース B に不正な入力を送っても 403（422 ではない）。
- `routes/api.php` の書き込み系ルートをすべて列挙し、上の表に載っていないルートがあればテストが失敗する（新しい書き込み API を足したときに認可テストを書き忘れないため）。

### WP-B0 で承認された提案（`wp-b0-plan.md` 6 章、2026-10-16 承認）

1. B0 の PR は `main` 向け。B1 の最初に `develop` を作り、B1 以降の PR は `develop` 向け。
2. 招待コードは紛らわしい文字（0/O・1/I/L）を除いた英数字 8 文字（表示は `K7Q2-MX9P` のように 4 文字ずつ区切る）。受付 API（STAFF-05）にも `throttle` を付ける。
3. Echo（リアルタイム接続）はログイン中だけ接続する。
   - **注意: 将来、未ログインでも混雑状況を見られるようにする場合は、Echo をログインなしで接続するように変更する必要がある**（`frontend/src/lib/echo.ts` の `getEcho()` がトークン未設定なら null を返す部分と、ログアウト時に切断する処理）。
4. `/me` は B1 で id / name / email、B2 で `role`、B4 で担当ブースを追加する。
5. `doctrine/dbal` は外す（既存カラムの型変更が必要になったら戻す）。

## 未解決の質問

なし
