# CourtQ — CLAUDE.md

CourtQ（コートキュー = Court + Queue）は、バスケットボールのアリーナの試合当日に、飲食・物販ブースの**混雑状況（4 段階 + 待ち時間）**と**メニューごとの在庫状況**をリアルタイムで確認できる Web アプリ（PWA）。
作業計画（WP-B0〜B10）は `claude-code-booth-prompt.md` を参照。アプリ名は `frontend/src/constants.ts`（`APP_NAME`）と `APP_NAME` / `VITE_APP_NAME` で一元管理する。

## Stack

| Layer | Tech |
|---|---|
| Frontend | React 18 / TypeScript (strict) / Vite 5 / Zustand / Axios / Laravel Echo + pusher-js / vite-plugin-pwa |
| Backend | Laravel 12 / PHP 8.2 / Sanctum (Bearer token) / Queue (`database` driver) / Cache (`database` driver) |
| DB | MySQL 8.0（テストは sqlite in-memory） |
| Local infra | Docker Compose（nginx / php-fpm / queue worker / mysql / node） |

## Directory map

```
backend/            Laravel 本体
  app/Http/Controllers/  Auth, Health（以降 WP で Game, Booth, Favorite, Staff, Admin を追加）
  app/Http/Requests/     FormRequest（バリデーション）
  app/Http/Resources/    API Resource（レスポンス整形）
  app/Services/          業務ロジック（Controller から呼ぶ）
  app/Policies/          認可
  database/factories/    テスト用 Factory
  routes/api.php         全 API ルート（/api プレフィックス）
  tests/Feature/         機能テスト
  docs/api-examples.php  API 仕様書の説明・例（人が書く部分）
frontend/src/
  constants.ts           アプリ名・localStorage キー・API キャッシュ名
  api/client.ts          Axios（トークン自動付与・401 で自動ログアウト）
  store/auth.ts          Zustand 認証ストア
  lib/echo.ts            Echo シングルトン（公開チャンネルのみ）
  components/, hooks/, pages/, types/
courtq-logo/        ロゴ（確定版。再デザインしない）。frontend/public と src/assets に使用分をコピー済み
docker/             nginx / php / mysql 設定（開発用）
scripts/            setup-backend.sh（初回セットアップ）, smoke-test.sh（API スモークテスト）
frontend/e2e/       Playwright E2E（API はモック、バックエンド不要）
docs/               設計書・API 仕様（api-endpoints.md は自動生成）
.github/workflows/ci.yml  PR ごとに backend test + frontend build + e2e
```

## How to run

```bash
cp .env.example .env
docker compose up -d --build
bash scripts/setup-backend.sh        # 初回のみ
# App: http://localhost  /  API: http://localhost/api  /  Vite: http://localhost:5173
```

## Tests / build

```bash
cd backend && php artisan test         # sqlite :memory:（phpunit.xml で設定）
cd backend && php artisan route:list   # ルート競合がないこと
cd frontend && npm run build           # tsc（strict）+ vite build
cd frontend && npm run e2e             # Playwright（API はモック。初回は npx playwright install chromium）
```

ホストで frontend を動かすときは `npm ci` をホストで実行する（コンテナで入れた node_modules は Linux 用バイナリのため、`docker compose up` の `react` サービスが `frontend/node_modules` を上書きする点に注意）。

## Coding rules

- Frontend: TypeScript strict、`any` 禁止。UI 文言は日本語。アプリ名は `APP_NAME` を使う（直書きしない）。
- Backend: バリデーションは FormRequest、レスポンスは Resource、認可は Policy（app/Policies）+ `$this->authorize()`。拒否時は Handler が共通の 403 `{"message":"この操作は許可されていません"}` を返す。
- レスポンス形式: 成功 `{ "data": ... }`、エラー / メッセージ `{ "message": ... }`（422 のみ `errors` を追加）。メッセージは日本語。
- **ロールをクライアントから受け取らない**（`role` は `$fillable` に入れない。付与はサーバー側の処理だけ）。
- 新しい環境変数は必ず `.env.example`（root / backend / frontend の該当箇所）に追加する。秘密情報はコミットしない。
- ブランド色は紺 `#14213D` / オレンジ `#FF7A1A`。**混雑の状態色（緑・黄・橙・赤）はブランドのオレンジと別の色**を使い、必ず文言（空いている / やや混雑 / 混雑 / 非常に混雑）と一緒に表示する（色だけで伝えない）。
- **B.LEAGUE の名称・チームロゴ・公式ブランドを UI・デモデータに使わない。** デモのチーム・会場・ブースはすべて架空にする。
- スコープ外のものは作らない（画像アップロード、外部の日程サイトのスクレイピング／自動同期など）。

## Backend layering（service-first）

| Layer | 役割 | やらないこと |
|---|---|---|
| FormRequest | バリデーションのみ | 認可・業務ロジック |
| Policy (`app/Policies`) | 認可のみ | クエリ・更新処理 |
| Controller | 受信 → `$this->authorize()` → Service 呼び出し → Resource を返す。1アクション ~15行まで | 業務ロジック、複数ステップの DB 処理、トークン生成、ジョブ dispatch |
| Service (`app/Services`) | 業務ロジックとトランザクション。依存はコンストラクタ注入。業務ルール違反は `ServiceException`（Handler が `{message}` + status に変換） | `Request` オブジェクトを受け取る、JSON を組み立てる |
| Resource | JSON 整形の唯一の場所 | — |

- 再利用するクエリ条件は Eloquent の local scope にする。**Repository パターンは導入しない**（`app/Repositories` を作らない）。
- **新機能は service-first で書く**: まず Service とそのテスト（`tests/Feature/Services`）、次に Controller / Route / Resource。
- 新しい Service には Feature テストを必ず付ける。
- ログイン処理は `AuthService` に集約する（外部 ID プロバイダを後から足せるようにするため。`users.provider` / `provider_user_id` 列は追加済み。拡張方法は AuthService のコメントを参照）。
- `env()` は `config/` の中でのみ使う（本番の `config:cache` 後は null になる）。アプリコードは `config()` を使う。

## 認可（書き込み API の守り方）

- 混雑・在庫を書き込めるのは staff（自分のブースのみ）と admin だけ。**サーバー側で必ず強制する**: `auth:sanctum` → `role:staff,admin` / `role:admin`（`EnsureRole`）→ Policy（`BoothPolicy@manage`: admin、または `booth_staff` に含まれる）→ FormRequest → `throttle`。
- CORS は直接の API 呼び出しを防がない（ブラウザの画面からの呼び出しを制限するだけ）。守っているのはサーバー側の認可。
- 認可のテストを省略しない。特に「一般ユーザーが自分のトークンで書き込み API を直接叩くと 403」を全書き込み API で検証する。

## Deploy

- `main` への push で Railway（API: `backend/Dockerfile`、web + worker + MySQL）と Vercel（SPA: `frontend/`）が自動デプロイされる。手順・環境変数一覧は `docs/deployment.md`（WP-B10 で作成）。
- 本番コンテナは起動時に `migrate --force` → `config:cache` → `route:cache`。**ルートにクロージャを使わない**（route:cache が失敗する。`DeploymentConfigTest` が検知）。
- CORS は `CORS_ALLOWED_ORIGINS`（ワイルドカード不可。Vercel の URL を末尾スラッシュなしで指定）。
- 稼働確認は `GET /api/health`（DB 接続を確認する）と `bash scripts/smoke-test.sh <api-url> [frontend-url]`。
- ダッシュボード操作（Railway / Vercel / Pusher）は人間が行う。必ず「Manual steps」として手順を出す。

## Realtime（Pusher Channels）

- **ゲームごとに 1 つの公開チャンネル `game.{gameId}`**（内容が機微でなく、閲覧者ごとの認証負荷を避けるため）。チャンネル認証 API（`/api/broadcasting/auth`）は無い。
- イベントは `ShouldBroadcast`（キュー経由で worker が送信）。ペイロードは小さく保つ（Pusher は約 10KB 上限）。
- フロント: `src/lib/echo.ts`（シングルトン。`VITE_PUSHER_APP_KEY` 未設定なら null でリアルタイム無効）、`src/hooks/useRealtime.ts`。再接続したら一覧を取得し直す。
- テスト: バックエンドは `Event::fake` とダミーキー。フロントの E2E は `e2e/helpers/fakePusher.ts` で WebSocket を偽の Pusher サーバーに差し替える（キー不要）。リアルタイムの E2E は Pusher キー設定済みの dev サーバー（ポート 5175）を使う。

## PWA

- `vite-plugin-pwa`（`frontend/vite.config.ts`）。アプリシェルはプリキャッシュ、`GET /api/*` は NetworkFirst（3 秒でタイムアウト、最大 5 分）。キャッシュ名は `courtq-api`。
- API キャッシュはユーザー固有データ（お気に入りなど）を含むため、ログイン/ログアウト時に破棄する（`src/lib/pwa.ts`、`store/auth.ts`）。
- オフライン UI: `OfflineBanner`、`OfflineFallback`、`useOnlineStatus`。
- アイコンは `frontend/public/icons`（再生成: `cd frontend && node scripts/generate-icons.mjs`。元は `public/favicon.svg` と `public/icons/icon-maskable.svg`）。

## Docs

- 設計書は `docs/`。**Markdown が正本**。
- **`docs/api-endpoints.md` は自動生成**。直接編集しない。ルートや FormRequest を変えたら `backend/docs/api-examples.php` に説明・例を追加/更新し、`cd backend && php artisan docs:api` を実行して一緒にコミットする（忘れると `ApiDocsTest` / `ApiContractTest` が CI で落ちる）。
- DB のテーブルやカラムを変えたら ER 図（Mermaid）も更新する。
- `docs/self-assessment.md` は「実装・テスト済み」と「実環境で確認済み」を区別して、事実だけを書く。確認していないことを確認済みと書かない。

## Git workflow

- WP ごとにブランチ（`feature/wp-b2-data-model` など）→ `develop` へ PR → CI green を確認して merge → 検証後 `develop` → `main`。
- コミットは小さく、Conventional Commits。**コミットメッセージは日本語**で書く。
  - 形式: `<type>(<scope>): <日本語の要約>`
  - type: `feat` / `fix` / `refactor` / `test` / `docs` / `chore` / `ci`
  - scope: `backend` / `frontend` / `docker` / `ci` など（任意）
  - 本文（body）も日本語。「何を」より「なぜ」を書く
  - 例: `feat(backend): スタッフが担当ブースの混雑を更新する API を追加`
  - **Co-Authored-By に Claude（AI）を入れない。** PR の説明にも「Generated with Claude Code」等の表記を入れない
- **PR のタイトルと説明も日本語**（Conventional Commits の type を付ける）。
- `main` への push が本番デプロイのトリガー。
- 作業完了の前に必ず test / route:list / build を実行し、実行したものを報告する。
