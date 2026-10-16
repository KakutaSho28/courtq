# 進捗ログ

新しいセッションで再開するときは、まずこのファイルと `claude-code-booth-prompt.md`・`CLAUDE.md` を読む。
各 WP の最後に更新する（完了 / 次 / 決定事項 / 未解決の質問）。

## 現在地

| WP | 状態 | ブランチ / PR |
|---|---|---|
| WP-B0 計画 | ✅ 計画書を作成。**ユーザーの OK 待ち** | `feature/wp-b0-plan` |
| WP-B1 基盤 | ⬜ 未着手（B0 の OK 後） | — |
| WP-B2〜B10 | ⬜ 未着手 | — |

## 完了

### WP-B0（2026-10-16）

- `claude-code-booth-prompt.md` と Spovie（`KakutaSho28/spovie` の `main` / `89e6bfb`、読み取り専用で clone）を読み、ファイルごとの計画を [`wp-b0-plan.md`](wp-b0-plan.md) にまとめた。
- 実行環境を確認: PHP 8.3.6 / Composer / Node 22 / Playwright の Chromium はあり。**Docker デーモンは無い**（`docker compose` 系は未検証として扱う）。
- 実行したテスト・ビルド: なし（コードがまだ無いため）。

## 次にやること

1. ユーザーから B0 の OK と、`wp-b0-plan.md` 6 章の質問への回答をもらう。
2. WP-B1: `main` から `develop` を作成 → `feature/wp-b1-bootstrap` で計画書 4 章の C / A / N を実施 → test / route:list / docs:api / build / e2e を実行 → `develop` 向け PR。

## 決定事項

- Spovie は読み取り専用の参考。Spovie の業務ドメイン（動画・アノテーション・クリップ・共有・コメント・チーム・S3/R2）は持ち込まない。
- 作業指示が「Spovie にある」と想定していたが実際には無いもの（日本語の 401/404/429、`/me`、日本語バリデーション、ログインの throttle、`constants.ts`）は B1 で新規作成する。
- リアルタイムは公開チャンネル `game.{gameId}` のみ。`/api/broadcasting/auth` は作らない（Spovie の private チャンネル用の認証処理は削除）。

## 未解決の質問（ユーザーに確認中）

`wp-b0-plan.md` の 6 章を参照:

1. B0 の PR を `main` 向けにし、B1 の最初に `develop` を作る案でよいか
2. 招待コードを「紛らわしい文字を除いた英数字 8 文字」にしてよいか
3. Echo の接続はログイン中だけでよいか
4. `/me` を B1 で最小限 → B2・B4 で項目を追加する進め方でよいか
5. `doctrine/dbal` を外してよいか
