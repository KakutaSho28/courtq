# CourtQ（コートキュー）

![CourtQ](courtq-logo/courtq-logo.svg)

バスケットボールのアリーナの試合当日に、飲食・物販ブースの**混雑状況（4 段階 + 待ち時間）**と、メニューごとの**在庫状況**をリアルタイムで確認できる Web アプリ（PWA）です。テーマパークの待ち時間表示のように、来場者が「いま空いているブース」をひと目で選べます。

> **開発状況**: 基盤（認証・ヘルスチェック・CI・デプロイ設定）まで完了。ゲーム・ブース・混雑/在庫の機能は順次追加します（作業計画: [`claude-code-booth-prompt.md`](claude-code-booth-prompt.md)）。
> デモ・架空データについて: 試合・チーム・会場・ブースはすべて架空です。実在のリーグ・チームとは関係ありません。

## 技術スタック

| 層 | 技術 |
|---|---|
| Frontend | React 18 / TypeScript (strict) / Vite / Zustand / Axios / Laravel Echo + pusher-js / PWA |
| Backend | Laravel 12 / PHP 8.2 / Sanctum（Bearer トークン） / Queue・Cache（database） |
| DB | MySQL 8.0 |
| Realtime | Pusher Channels（キー未設定ならリアルタイム機能は無効で、通常どおり動作） |
| 開発環境 | Docker Compose |
| 本番 | Railway（API + worker + MySQL） / Vercel（フロント） |

## セットアップ（ローカル）

```bash
cp .env.example .env
docker compose up -d --build
bash scripts/setup-backend.sh   # 初回のみ（composer install・.env・key・migrate）
```

- アプリ: http://localhost （Vite dev server は http://localhost:5173）
- API: http://localhost/api （ヘルスチェック: `GET /api/health`）

テスト・ビルド:

```bash
cd backend && php artisan test
cd frontend && npm ci && npm run build && npm run e2e   # 初回は npx playwright install chromium
bash scripts/smoke-test.sh http://localhost/api          # 起動中の API の疎通確認
```

## デモデータとデモアカウント

`docker compose run --rm php php artisan db:seed` で、架空の会場・試合（今日の試合を含む 2 試合）・8 ブース・メニュー・在庫・混雑を投入します（何度実行しても重複しません。今日の試合の混雑は実行時刻を基準に作り直します）。

| メール | 役割 | 担当ブース |
|---|---|---|
| `admin@example.com` | 管理者 | 全ブース |
| `staff1@example.com` | スタッフ | ファーストブレイク・バーガー、ベースライン・ラーメン |
| `staff2@example.com` | スタッフ | ウィングス・グッズ東 |
| `user1@example.com` / `user2@example.com` | 一般ユーザー | – |

パスワードはすべて `courtq-demo-1234`（環境変数 `DEMO_PASSWORD` で変更可）です。**公開デモ用の誰でも知る値なので、実運用のデータがある環境ではシードしないでください。** 管理者は画面・API からは作れません。実運用では `php artisan app:create-admin <email>` で作成します（既存ユーザーならそのユーザーを管理者にし、新規ならパスワードを 1 回だけ表示します）。

## ドキュメント

[`docs/`](docs/README.md) を参照してください。API 仕様は [`docs/api-endpoints.md`](docs/api-endpoints.md)（`php artisan docs:api` で自動生成）です。開発のルールは [`CLAUDE.md`](CLAUDE.md) にあります。

## ロゴ

`courtq-logo/` に確定版のロゴ一式があります。ブランドカラーは紺 `#14213D` とオレンジ `#FF7A1A` です。
