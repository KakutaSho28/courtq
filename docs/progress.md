# 進捗ログ

新しいセッションで再開するときは、まずこのファイルと `claude-code-booth-prompt.md`・`CLAUDE.md` を読む。
各 WP の最後に更新する（完了 / 次 / 決定事項 / 未解決の質問）。

## 現在地

| WP | 状態 | ブランチ / PR |
|---|---|---|
| WP-B0 計画 | ✅ 完了（2026-10-16 にユーザーが承認） | [#4](https://github.com/KakutaSho28/courtq/pull/4)（`develop` 向け） |
| WP-B1 基盤 | ✅ 完了（別の作業で実装済み。2026-10-16 に検証して完了扱いにした） | [#1](https://github.com/KakutaSho28/courtq/pull/1)・[#2（Laravel 12）](https://github.com/KakutaSho28/courtq/pull/2)、`develop` にマージ済み |
| WP-B2 データモデル | ✅ 完了（`develop` にマージ済み） | [#3](https://github.com/KakutaSho28/courtq/pull/3) |
| WP-B3 読み取り API | 🔄 実装・検証済み、PR 作成済み（マージ待ち） | `feature/wp-b3-read-api` → `develop`（下の PR リンク参照） |
| WP-B4〜B10 | ⬜ 未着手 | — |

## 完了

### WP-B0（2026-10-16）

- `claude-code-booth-prompt.md` と Spovie（`KakutaSho28/spovie` の `main` / `89e6bfb`、読み取り専用で clone）を読み、ファイルごとの計画を [`wp-b0-plan.md`](wp-b0-plan.md) にまとめた。
- 実行環境を確認: PHP 8.3.6 / Composer / Node 22 / Playwright の Chromium はあり。**Docker デーモンは無い**（`docker compose` 系は未検証として扱う）。
- 実行したテスト・ビルド: なし（コードがまだ無いため）。

## 次にやること

1. WP-B3 の PR をユーザーがマージする。
2. WP-B4: ロール（`EnsureRole`）・Policy・STAFF API・招待コード・履歴・throttle。「一般ユーザーが書き込み API を直接叩くと 403」を全書き込み API でテストする。B3 で用意した `BoothService::forgetList()` を、混雑・在庫の更新から呼ぶ。`/me` に `role` と担当ブースを追加する。招待コードは紛らわしい文字を除いた 8 文字。

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

### WP-B0 で承認された提案（`wp-b0-plan.md` 6 章、2026-10-16 承認）

1. B0 の PR は `main` 向け。B1 の最初に `develop` を作り、B1 以降の PR は `develop` 向け。
2. 招待コードは紛らわしい文字（0/O・1/I/L）を除いた英数字 8 文字（表示は `K7Q2-MX9P` のように 4 文字ずつ区切る）。受付 API（STAFF-05）にも `throttle` を付ける。
3. Echo（リアルタイム接続）はログイン中だけ接続する。
   - **注意: 将来、未ログインでも混雑状況を見られるようにする場合は、Echo をログインなしで接続するように変更する必要がある**（`frontend/src/lib/echo.ts` の `getEcho()` がトークン未設定なら null を返す部分と、ログアウト時に切断する処理）。
4. `/me` は B1 で id / name / email、B2 で `role`、B4 で担当ブースを追加する。
5. `doctrine/dbal` は外す（既存カラムの型変更が必要になったら戻す）。

## 未解決の質問

なし
