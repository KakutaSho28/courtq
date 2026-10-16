# 進捗ログ

新しいセッションで再開するときは、まずこのファイルと `claude-code-booth-prompt.md`・`CLAUDE.md` を読む。
各 WP の最後に更新する（完了 / 次 / 決定事項 / 未解決の質問）。

## 現在地

| WP | 状態 | ブランチ / PR |
|---|---|---|
| WP-B0 計画 | ✅ 完了（2026-10-16 にユーザーが承認） | [#4](https://github.com/KakutaSho28/courtq/pull/4)（`develop` 向け） |
| WP-B1 基盤 | ✅ 完了（別の作業で実装済み。2026-10-16 に検証して完了扱いにした） | [#1](https://github.com/KakutaSho28/courtq/pull/1)・[#2（Laravel 12）](https://github.com/KakutaSho28/courtq/pull/2)、`develop` にマージ済み |
| WP-B2 データモデル | 🔄 レビュー・検証済み、マージ待ち | [#3](https://github.com/KakutaSho28/courtq/pull/3)（`develop` 向け） |
| WP-B3〜B10 | ⬜ 未着手 | — |

## 完了

### WP-B0（2026-10-16）

- `claude-code-booth-prompt.md` と Spovie（`KakutaSho28/spovie` の `main` / `89e6bfb`、読み取り専用で clone）を読み、ファイルごとの計画を [`wp-b0-plan.md`](wp-b0-plan.md) にまとめた。
- 実行環境を確認: PHP 8.3.6 / Composer / Node 22 / Playwright の Chromium はあり。**Docker デーモンは無い**（`docker compose` 系は未検証として扱う）。
- 実行したテスト・ビルド: なし（コードがまだ無いため）。

## 次にやること

1. #3（WP-B2）と #4（B0 の文書）をユーザーがマージする。
2. WP-B3（読み取り API: GAME / BOOTH / FAV とキャッシュ）。service-first で、まず Service とテストから。

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

### WP-B0 で承認された提案（`wp-b0-plan.md` 6 章、2026-10-16 承認）

1. B0 の PR は `main` 向け。B1 の最初に `develop` を作り、B1 以降の PR は `develop` 向け。
2. 招待コードは紛らわしい文字（0/O・1/I/L）を除いた英数字 8 文字（表示は `K7Q2-MX9P` のように 4 文字ずつ区切る）。受付 API（STAFF-05）にも `throttle` を付ける。
3. Echo（リアルタイム接続）はログイン中だけ接続する。
   - **注意: 将来、未ログインでも混雑状況を見られるようにする場合は、Echo をログインなしで接続するように変更する必要がある**（`frontend/src/lib/echo.ts` の `getEcho()` がトークン未設定なら null を返す部分と、ログアウト時に切断する処理）。
4. `/me` は B1 で id / name / email、B2 で `role`、B4 で担当ブースを追加する。
5. `doctrine/dbal` は外す（既存カラムの型変更が必要になったら戻す）。

## 未解決の質問

なし
