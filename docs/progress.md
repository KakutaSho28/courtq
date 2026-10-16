# 進捗ログ

新しいセッションで再開するときは、まずこのファイルと `claude-code-booth-prompt.md`・`CLAUDE.md` を読む。
各 WP の最後に更新する（完了 / 次 / 決定事項 / 未解決の質問）。

## 現在地

| WP | 状態 | ブランチ / PR |
|---|---|---|
| WP-B0 計画 | ✅ 完了（2026-10-16 にユーザーが承認） | `feature/wp-b0-plan` → `main` |
| WP-B1 基盤 | 🔄 着手 | `feature/wp-b1-bootstrap` → `develop` |
| WP-B2〜B10 | ⬜ 未着手 | — |

## 完了

### WP-B0（2026-10-16）

- `claude-code-booth-prompt.md` と Spovie（`KakutaSho28/spovie` の `main` / `89e6bfb`、読み取り専用で clone）を読み、ファイルごとの計画を [`wp-b0-plan.md`](wp-b0-plan.md) にまとめた。
- 実行環境を確認: PHP 8.3.6 / Composer / Node 22 / Playwright の Chromium はあり。**Docker デーモンは無い**（`docker compose` 系は未検証として扱う）。
- 実行したテスト・ビルド: なし（コードがまだ無いため）。

## 次にやること

1. WP-B1: `main` から `develop` を作成 → `feature/wp-b1-bootstrap` で計画書 4 章の C / A / N を実施 → test / route:list / docs:api / build / e2e を実行 → `develop` 向け PR。

## 決定事項

- Spovie は読み取り専用の参考。Spovie の業務ドメイン（動画・アノテーション・クリップ・共有・コメント・チーム・S3/R2）は持ち込まない。
- 作業指示が「Spovie にある」と想定していたが実際には無いもの（日本語の 401/404/429、`/me`、日本語バリデーション、ログインの throttle、`constants.ts`）は B1 で新規作成する。
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
