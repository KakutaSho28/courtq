# WP-B0 計画: Spovie の基盤から CourtQ を作る

作業指示（`claude-code-booth-prompt.md`）と Spovie リポジトリ（`KakutaSho28/spovie`、`main` の `89e6bfb` を読み取り専用で確認）をもとに、
**どのファイルをコピー / 手直し / 新規作成 / 持ち込まないか**を決めた計画書です。WP-B1 以降はこの表に沿って進めます。

凡例:

| 記号 | 意味 |
|---|---|
| **C** | そのままコピー（名前の置換程度） |
| **A** | コピーして手直し（Spovie 固有の部分を削る・CourtQ 用に書き換える） |
| **N** | 新規作成（Spovie に無い） |
| **✕** | 持ち込まない（Spovie の業務ドメインのコード） |

---

## 1. 結論（要点）

- Spovie の基盤（Docker / Laravel 10 + Sanctum / Service 層ルール / CI / 本番 Dockerfile / Railway・Vercel 設定 / React + TS + Vite / API クライアント / 認証ストア / Echo / PWA / e2e）は **ほぼそのまま流用できる**。
- ただし作業指示が「Spovie にある」と想定しているもののうち、**実際には Spovie に無いもの**があるため、WP-B1 で新規に作る（→ 4 章）。
  - 401 / 404 / 422 / 429 の日本語 JSON エラー（Spovie の Handler は `ServiceException` と 403 だけ）
  - `GET /api/me`（AUTH-04）
  - 日本語のバリデーションメッセージ（Spovie は `locale=en`）
  - ログインの `throttle`
  - `frontend/src/constants.ts`（アプリ名・localStorage キーの一元管理）
- リアルタイムは **Spovie = private チャンネル + `/api/broadcasting/auth`**、**CourtQ = 公開チャンネル `game.{gameId}`・認証 API なし** と方式が違うので、`echo.ts` と `BroadcastServiceProvider` は手直しが必要。
- Spovie のチーム招待トークンは「チームごとに 1 本・無期限・何回でも使える」方式。CourtQ の招待コードは「1 回限り・期限付き（7 日）」なので、**流用するのは「ランダム値の生成」と「未ログインで開いたらログイン後に戻る」フロー（`pendingInvite.ts`）だけ**。

---

## 2. 現状（courtq リポジトリ）

| 項目 | 状態 |
|---|---|
| ブランチ | `main`（`chore: Spovieの基盤を流用してCourtQを初期化` まで）。`develop` は未作成 |
| 中身 | `CLAUDE.md` / `README.md` / `claude-code-booth-prompt.md` / `courtq-logo/` / `docs/README.md` のみ。**`backend/` `frontend/` などのコードはまだ無い** |
| 注意 | `README.md` の「基盤まで完了」、`CLAUDE.md` のディレクトリ表は **WP-B1 完了後の状態を先に書いたもの**。B1 で実態を合わせる |

## 3. 実行環境（このクラウドセッション）

| ツール | バージョン | 備考 |
|---|---|---|
| PHP | 8.3.6（pdo_sqlite / sqlite3 / mbstring あり） | 本番・CI は 8.2。8.3 で `php artisan test` を実行する |
| Composer | あり | |
| Node / npm | 22.22 / 10.9 | CI は Node 20 |
| Chromium（Playwright） | `/opt/pw-browsers` に導入済み | e2e は実行可能 |
| Docker | CLI のみ。**デーモンが無く実行不可** | `docker compose up`・本番イメージのビルドは「未検証」として報告する |

---

## 4. ファイルごとの計画

### 4.1 リポジトリ直下・インフラ

| Spovie のファイル | 区分 | CourtQ での扱い |
|---|---|---|
| `.gitignore` / `.gitattributes` | 済 | courtq に既にある（Spovie と同等）。変更なし |
| `.env.example`（root） | A | `DB_DATABASE=courtq_db` などに改名。`VITE_MAX_UPLOAD_MB` を削除。`APP_NAME=CourtQ` / `VITE_APP_NAME=CourtQ` を追加 |
| `docker-compose.yml` | A | `container_name` を `courtq_*` に。queue のコメント（FFmpeg）と `--timeout=600` を短く（90）。`VITE_MAX_UPLOAD_MB` を削除し `VITE_APP_NAME` を追加 |
| `docker/php/Dockerfile` | A | `ffmpeg` と S3 用 `composer require` を削除 |
| `docker/php/uploads.ini` | ✕ | 動画アップロード用。不要（画像アップロードもスコープ外） |
| `docker/nginx/default.conf` | A | `client_max_body_size` を 2M 程度に（CSV 取り込み用）、`/storage` の動画配信設定と長い `fastcgi_read_timeout` を削除 |
| `docker/mysql/my.cnf` | C | |
| `scripts/setup-backend.sh` | A | DB 名を `courtq_*` に。`FILESYSTEM_DISK` の置換と `storage:link` を削除。最後に `db:seed`（B2 以降）の案内 |
| `scripts/smoke-test.sh` | A | B1: 登録 → ログイン → `/me` → `/health` → 未認証 401 → CORS。B3 以降でゲーム・ブース一覧、B4 で「一般ユーザーの書き込み 403」を追加 |
| `.github/workflows/ci.yml` | C | backend test / frontend build / e2e の 3 ジョブ。そのまま使える。`route:list` のステップを追加（A） |
| `CLAUDE.md` / `README.md` | 済 → A | courtq 用に既に書かれている。B1 で実態（コマンド・ディレクトリ）と合わせる |
| `claude-code-prompt.md`（Spovie の作業指示） | ✕ | courtq には `claude-code-booth-prompt.md` がある |

### 4.2 Backend — 設定・基盤

| Spovie のファイル | 区分 | CourtQ での扱い |
|---|---|---|
| `backend/artisan`, `bootstrap/*`, `public/{index.php,.htaccess,robots.txt}`, `storage/**/.gitignore`, `.editorconfig`, `.gitattributes`, `.gitignore`, `.dockerignore` | C | Laravel 標準 |
| `backend/public/favicon.ico` | ✕ | API サーバーに不要（フロントで配信） |
| `backend/resources/*`, `package.json`, `vite.config.js`, `routes/web.php`, `README.md` | A | Laravel 標準の雛形。`README.md` は CourtQ 用に短く書き直す |
| `composer.json` / `composer.lock` | A | `league/flysystem-aws-s3-v3`（S3）と `doctrine/dbal`（既存カラム変更用。新規マイグレーションのみなので不要）を削除し、lock を再生成 |
| `phpunit.xml` | C | sqlite in-memory |
| `.env.example`（backend） | A | `APP_NAME=CourtQ`、`CACHE_DRIVER=database`、`QUEUE_CONNECTION=database`、Media / AWS の項目を削除、`STAFF_INVITATION_TTL_DAYS` などは各 WP で追加 |
| `config/app.php` | A | `locale=ja` / `fallback_locale=en` / `timezone=Asia/Tokyo`、`frontend_url` は維持 |
| `config/cors.php` | A | コメントの例を courtq の URL に |
| `config/auth.php`, `broadcasting.php`, `cache.php`, `database.php`, `hashing.php`, `logging.php`, `mail.php`, `queue.php`, `sanctum.php`, `services.php`, `session.php`, `view.php` | C | |
| `config/filesystems.php` | A | `s3` ディスクを削除（local / public だけ残す） |
| `config/media.php` | ✕ | 動画用 |
| `config/demo.php` | A | `DEMO_PASSWORD` だけ残す（既定値を CourtQ 用に）。YouTube ID は削除 |
| `database/migrations` の Laravel 標準 4 本（users / password_reset_tokens / failed_jobs / personal_access_tokens） | C | `users` に `role`・`provider`・`provider_user_id` を足すのは B2（別マイグレーション） |
| `create_jobs_table` | A | 日付を付け直してコピー（queue=database 用） |
| — | N | `create_cache_table`（`CACHE_DRIVER=database` 用。Spovie には無い） |
| その他のマイグレーション（videos / annotations / share_links / clips / teams / team_members / comments ほか） | ✕ | |
| `app/Http/Kernel.php` | A | B4 で `role` エイリアス（`EnsureRole`）を追加 |
| `app/Http/Middleware/*`（Authenticate / TrustProxies 等の標準 9 本） | C | TrustProxies は Spovie の設定（Railway の X-Forwarded-*）を維持 |
| `app/Providers/AppServiceProvider.php`, `EventServiceProvider.php` | C | |
| `app/Providers/RouteServiceProvider.php` | A | B1 で `login` 用の RateLimiter を追加、B4 で staff 書き込み用（30/分/ユーザー）を追加 |
| `app/Providers/AuthServiceProvider.php` | A | Spovie の Policy 対応表を空に。B4 で `Booth => BoothPolicy` |
| `app/Providers/BroadcastServiceProvider.php` | A | **`Broadcast::routes()` を削除**（公開チャンネルのみで認証 API が無いため）。`routes/channels.php` の読み込みだけ残す |
| `routes/channels.php` | A | video / annotation のチャンネル定義を削除。公開チャンネルは定義不要なので、その旨のコメントだけ |
| `routes/console.php` | C | |
| `app/Console/Kernel.php` | C | |
| `backend/docker/{nginx.conf.template, php-fpm.conf, php.ini, supervisord.conf}` | A | ファイル名・`zz-spovie` を `courtq` に。nginx / php.ini のアップロード上限（210M）を小さく |
| `backend/docker/start-web.sh` | A | `SEED_DEMO` のコメントを CourtQ のデモデータに |
| `backend/Dockerfile` | A | `ffmpeg` を削除、`spovie` の名前を置換。それ以外（vendor ステージ・opcache・`CMD start-web`）は維持 |
| `backend/railway.json` | C | healthcheck `/api/health` |
| `backend/railway.worker.json` | A | `--timeout=600` → `--timeout=90`（重い FFmpeg ジョブが無いため） |
| `frontend/vercel.json` | C | SPA の rewrite |

### 4.3 Backend — アプリコード

| Spovie のファイル | 区分 | CourtQ での扱い |
|---|---|---|
| `app/Exceptions/ServiceException.php` | C | |
| `app/Exceptions/Handler.php` | A + N | 既存の ServiceException / 403 に加え、`api/*` で **401「認証が必要です」・404「見つかりません」・429「リクエストが多すぎます…」** を日本語 `{message}` で返す処理を追加。422 は `lang/ja/validation.php` で日本語化し、`{message, errors}` 形式は Laravel 標準のまま |
| — | N | `lang/ja/validation.php`（+ `auth.php` 等の最小限）。項目名（`attributes`）も日本語に |
| `app/Services/AuthService.php` | A | トークン名を `courtq` に。外部 ID プロバイダ用の拡張ポイント（`loginWithProvider()` は作らず、コメントと設計書で方針だけ示す。カラムは B2） |
| `app/Http/Controllers/AuthController.php` | A + N | `me()`（AUTH-04）を追加。B1 時点では id / name / email / role（B2 でカラム追加後）、B4 で担当ブースを追加 |
| `app/Http/Controllers/Controller.php`, `HealthController.php`, `app/Services/HealthService.php` | C | `/api/health` は DB 接続を確認 |
| `app/Http/Requests/LoginRequest.php`, `RegisterRequest.php` | C | |
| `app/Http/Resources/AuthTokenResource.php` | A | user 部分を新しい `UserResource`（N）に切り出し、`/me` と共通化 |
| `app/Models/User.php` | A | videos / teams / comments のリレーションを削除。B2 で `role` の cast・`booths()`・`favorites()` |
| `database/factories/UserFactory.php` | C | B2 で `staff()` / `admin()` の state を追加 |
| `database/seeders/DatabaseSeeder.php` | A | B2 で `DemoSeeder` を呼ぶ |
| `database/seeders/DemoSeeder.php` | ✕ → N（B2） | 冪等（何回実行しても同じ結果）にする書き方だけ参考にし、中身は架空の会場・試合・ブースで新規作成 |
| `app/Console/Commands/GenerateApiDocs.php`, `app/Services/ApiDocsGenerator.php` | A | 仕組みは流用。末尾の「リアルタイム」節（Spovie のチャンネル表）と認証の説明を CourtQ 用に書き換え |
| `backend/docs/api-examples.php` | A | AUTH / health の例だけ残す。以降は WP ごとに追加 |
| `routes/api.php` | A | health / auth（register・login・logout・me）だけ。ログインに `throttle:login` |
| `app/Services/TeamService.php` | 参考 | 招待トークンの生成（`Str::random`）と「冪等な参加」の考え方を B4 の `StaffInvitationService` で参考にする（コピーはしない） |
| Spovie ドメイン: Controllers（Annotation / Clip / Comment / Share / Team / Video / VideoUpload）、Requests（9 本）、Resources（8 本）、Events（3 本）、Jobs、Models（7 本）、Policies（5 本）、Services（Annotation / Clip / ClipProcessing / Comment / MediaStorage / ShareLink / Team / Video / VideoUpload）、`app/Support/Ffmpeg.php`、Factories（5 本）、`demo-canvas.json` | ✕ | |

### 4.4 Backend — テスト

| Spovie のファイル | 区分 | CourtQ での扱い |
|---|---|---|
| `tests/TestCase.php`, `CreatesApplication.php`, `Unit/.gitkeep` | C | |
| `Feature/HealthTest.php`, `AuthFlowTest.php`, `Services/AuthServiceTest.php` | A | `/me` と 401 の日本語メッセージ、ログインの throttle（429）を追加 |
| `Feature/DeploymentConfigTest.php` | A | Origin の例を courtq に。クロージャルート検知はそのまま |
| `Feature/ApiDocsTest.php` | C | `docs/api-endpoints.md` とコードのずれ検知 |
| `Feature/ApiContractTest.php` | A | 仕組み（例と実レスポンスのキー一致）を流用。Spovie のテストケースは削除し auth / health だけ |
| `Feature/AuthorizationTest.php` | 参考 → N（B4） | 「他人のトークンで直接叩くと 403」の網羅テストの書き方を参考に、全書き込み API 用に新規作成 |
| `Feature/RealtimeEventsTest.php` | 参考 → N（B8） | `Event::fake` + ダミーキーの書き方を参考に新規作成 |
| `Feature/BroadcastAuthTest.php` | ✕ | チャンネル認証 API が無いため。代わりに B8 で「`/api/broadcasting/auth` が存在しない」ことと公開チャンネル名を検証 |
| `Feature/DemoSeederTest.php` | 参考 → N（B2） | |
| ClipDownload / Share / TeamApi / VideoListFilter / VideoShow / その他 Services テスト | ✕ | |

### 4.5 Frontend

| Spovie のファイル | 区分 | CourtQ での扱い |
|---|---|---|
| `package.json` / `package-lock.json` | A | `name` を `courtq-frontend`、`fabric` を削除して lock を再生成 |
| `tsconfig.json`, `src/vite-env.d.ts`, `src/main.tsx` | C | `vite-env.d.ts` に `VITE_APP_NAME` の型を追加（A） |
| `index.html` | A | タイトル・説明・`theme-color`（紺 `#14213D`）を CourtQ に。タイトルは `%VITE_APP_NAME%` で一元化 |
| `vite.config.ts` | A | manifest の name / short_name / 説明 / 色を CourtQ に、`API_CACHE='courtq-api'`。動画用・`/api/broadcasting/auth`・クリップの NetworkOnly ルールを削除。NetworkFirst（3 秒・5 分）は維持 |
| `playwright.config.ts` | C | 5174（通常）/ 5175（Pusher キーあり）/ 5176（PWA）/ 5180（モック API） |
| — | N | `src/constants.ts`（`APP_NAME`、localStorage キー `courtq_token` / `courtq_user`、`API_CACHE_NAME`、`STALE_THRESHOLD_MINUTES=15` は B5） |
| `src/api/client.ts` | C | Bearer 付与・401 で自動ログアウト・`X-Socket-ID` |
| `src/store/auth.ts` | A | キーを `constants.ts` から。`AuthUser` に `role`（B2 以降） |
| `src/lib/pwa.ts` | A | キャッシュ名を `constants.ts` から |
| `src/lib/realtime.ts` | C | |
| `src/lib/echo.ts` | A | **private → public**: `authorizer` を削除し `listenPrivate` を `listenChannel`（`echo.channel()`）に。キー未設定なら null の動作は維持 |
| `src/hooks/useRealtime.ts` | A | `useChannelEvent` を公開チャンネル用に。`useOnReconnect`（再接続で再取得）はそのまま |
| `src/hooks/useOnlineStatus.ts`, `useInstallPrompt.ts` | C | |
| `src/components/OfflineBanner.tsx`, `OfflineFallback.tsx`, `RealtimeBadge.tsx`, `InstallButton.tsx` | C | |
| `src/components/Layout.tsx` | A | ヘッダーのロゴを `courtq-logo` の SVG に、ナビは B1 ではログアウトのみ |
| `src/App.tsx` | A | ガード（`RequireAuth` / `GuestOnly`）を流用。B1 のルートは `/login` `/register` `/`（空のホーム）。B4〜B7 でロール別ガード（`RequireRole`）を追加 |
| `src/pages/Login.tsx`, `Register.tsx` | A | 文言・ロゴを CourtQ に |
| `src/lib/pendingInvite.ts` | A（B6） | 招待コード入力（S10）で「未ログイン → ログイン後に戻る」に流用。キーを `courtq_pending_invite` に |
| `src/styles.css` | A | トークン（CSS 変数）の構成は流用し、色を紺 / オレンジに置き換え。混雑の状態色（緑・黄・**ブランドと別の橙**・赤）と在庫バッジは B5 で追加。Spovie のキャンバス・動画用スタイルは削除 |
| `src/types/index.ts` | A | `AuthUser` だけ残す。ドメインの型は各 WP で追加 |
| `src/utils/time.ts` | ✕ → N（B5） | 動画の秒数表示用なので持ち込まない。「最終更新 ○分前」用の関数を新規作成 |
| `public/favicon.svg`, `public/icons/*` | N | `courtq-logo/` から作る: favicon = `courtq-app-icon.svg`、`icon.svg` = 同上、`icon-maskable.svg` = マークの周囲に余白（セーフゾーン 80%）を取った新規 SVG。PNG は `generate-icons.mjs` で再生成 |
| `scripts/generate-icons.mjs` | C | |
| `src/assets/`（ロゴ） | N | ヘッダー用に `courtq-logo.svg` / `courtq-logo-dark.svg` をコピー |
| `e2e/helpers/fakePusher.ts` | A | 公開チャンネルは購読時に auth が無いので、そのままでも動く想定。B8 で確認 |
| `e2e/helpers/mockApiServer.mjs` | A | 動画・チームのモックを削除。B1 は health / login / me、B9 でゲーム・ブース一覧 |
| `e2e/pwa.spec.ts` | A | 仕組み（Service Worker・オフライン時のキャッシュ表示・ログアウトで破棄）を流用し、対象画面を CourtQ に（B1 は最小限、B9 で本格化） |
| `e2e/realtime.spec.ts` | 参考 → N（B8） | |
| — | N | `e2e/auth.spec.ts`（B1: ログイン → ホーム → ログアウト。CI の e2e ジョブを空にしないため） |
| Spovie ドメイン: `AnnotationCanvas` / `ClipModal` / `CommentThread` / `FileUploadInput` / `Pagination` / `TimeInput`、`useHtml5VideoLoop` / `useYouTubePlayer`、`lib/{canvasScale,commentList,pagination}`、`types/youtube.d.ts`、pages（Annotate / Annotations / ClipStatus / Share / TeamDetail / TeamJoin / TeamNew / Teams / VideoNew / Videos）、e2e（canvas-roundtrip / demo-canvas / pagination / teams） | ✕ | `Pagination` は B7 の管理画面で必要になったら新規に書く |

### 4.6 Docs

| Spovie のファイル | 区分 | CourtQ での扱い |
|---|---|---|
| `docs/api-endpoints.md` | 自動生成 | B1 で `php artisan docs:api` により生成（auth / health） |
| `docs/04-setup.md` | A（B1） | セットアップ手順。Docker / ホスト実行の注意 |
| `docs/deployment.md` | A（B10） | Spovie の手順を土台に、作業指示 9 章の「Spovie デプロイの教訓」をすべて書く |
| `docs/pwa.md` | A（B9） | |
| `docs/01-requirements.md`, `02-basic-design.md`, `03-detailed-design.md`, `self-assessment.md` | N（B10） | 構成（章立て・✅⚠️⬜ の区別）だけ参考にし、内容は新規 |
| — | N | `docs/progress.md`（進捗ログ。各 WP の最後に更新） |

---

## 5. WP-B1 の完了条件（確認方法）

| 確認 | コマンド | このセッションで |
|---|---|---|
| バックエンドのテスト | `cd backend && php artisan test` | 実行する |
| ルート競合・クロージャなし | `cd backend && php artisan route:list` | 実行する |
| API 仕様の生成 | `cd backend && php artisan docs:api`（差分なし） | 実行する |
| フロントのビルド | `cd frontend && npm ci && npm run build` | 実行する |
| E2E | `cd frontend && npm run e2e` | 実行する（Chromium 導入済み） |
| Docker 開発環境 | `docker compose up -d --build` → `setup-backend.sh` | **未検証**（デーモンなし） |
| 本番イメージ | `docker build backend` | **未検証**（デーモンなし） |
| スモークテスト | `bash scripts/smoke-test.sh http://127.0.0.1:8000/api` | `php artisan serve` + sqlite で実行を試みる |
| CI | GitHub Actions | PR で確認 |

---

## 6. 確認したいこと（OK をもらう前に決めたい点）

1. **ブランチと PR の向き先**: `develop` はまだありません。作業指示では B1 で `main` / `develop` を作ることになっています。
   - 案: B0（この計画書）の PR は `main` 向け（ドキュメントのみ。デプロイ設定がまだ無いので本番への影響なし）。B1 の最初に `main` から `develop` を作り、B1 以降の PR は `develop` 向け。
2. **招待コードの形式**: スマホで手入力するため、64 文字のランダム文字列（Spovie 方式）ではなく **紛らわしい文字（0/O・1/I/L）を除いた英数字 8 文字**（例: `K7Q2-MX9P` のように 4 文字ずつ区切って表示）を提案します。総当たり対策として受付 API（STAFF-05）にも `throttle` を付けます。
3. **リアルタイムの接続条件**: 全 API がログイン必須なので、Echo も **ログイン中だけ接続**（Spovie と同じ）にします。公開チャンネルなので技術的には未ログインでも購読できますが、画面がログイン必須のため実害はありません。
4. **AUTH-04 `/me` の時期**: B1 で id / name / email を返す形で作り、B2 で `role`、B4 で担当ブース（`staff_booths`）を追加します。
5. **`doctrine/dbal` の削除**: CourtQ は新規マイグレーションだけなので外します（既存カラムの型変更が必要になったら戻します）。

## 7. スコープ外（作らない）

画像アップロード（R2/S3 含む）、FFmpeg、外部の日程サイトの取り込み・スクレイピング、外部 ID プロバイダの実装（カラムと設計書での方針のみ）、B.LEAGUE の名称・ロゴ・公式ブランド。
