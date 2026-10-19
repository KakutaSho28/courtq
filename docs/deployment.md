# デプロイ手順（Railway + Vercel）

CourtQ を **Railway**（API の web + キューの worker + MySQL）と **Vercel**（フロントの SPA）に公開する手順です。リアルタイムに使う **Pusher** は任意です（無くても動きます）。

- **ダッシュボードの操作は人が行います**（Claude は代行しません）。手順は「**4. ダッシュボードの手順**」に、順番どおり書いてあります。
- **秘密の値（APP_KEY・パスワード・Pusher の secret）は、チャットやコミットに貼らないでください。** この文書に書いてあるのは、名前と形だけです。
- `main` への push が本番のデプロイのトリガーです（Railway と Vercel が自動で再デプロイします）。最初は `develop` ブランチで試すこともできます（4 章の注記）。

## 1. 構成

```
ブラウザ（スマホ）
   │ https
   ├─▶ Vercel  … フロント（React の SPA / PWA）。frontend/ をビルドして配信
   │              │ https（VITE_API_BASE_URL）
   │              ▼
   └─▶ Railway  web    … API（nginx + php-fpm を 1 コンテナで）。backend/Dockerfile。/api/health で稼働確認
         │      worker … キュー（php artisan queue:work）。混雑・在庫の更新を Pusher へ配信する。同じイメージ
         │      MySQL  … Railway のプライベートネットワークの中だけ（公開しない）
         └─▶ Pusher（任意）… ブラウザへの WebSocket 配信。ゲームごとの公開チャンネル game.{gameId}
```

## 2. 守ること（Spovie のデプロイで時間を失った点）

| # | 守ること | 守らないと |
|---|---|---|
| 1 | Railway の **web サービスの Root Directory は `/backend`**（worker も同じ） | Dockerfile が見つからず、ビルドが失敗する / 別の場所を配信して HTML が返る |
| 2 | **公開ドメインの「ターゲットポート」を、`PORT` 変数と同じ値**にする（例: 両方 `8080`） | ドメインにつながらず 502。ヘルスチェックも失敗する |
| 3 | **DB の変数は、Railway の参照変数**にする（`DB_HOST=${{MySQL.MYSQLHOST}}` など。5 章の表）。**`DB_PASSWORD` を空にしない** | migrate が落ち続け、再起動ループになる |
| 4 | **MySQL サービスに、公開ドメイン・TCP プロキシを作らない** | データベースがインターネットに公開される |
| 5 | Railway が**自動で提案する変数に「Add」を押さない**（`.env.example` 由来のローカル開発用の値。`DB_HOST=127.0.0.1` など） | 本番がローカルの値で動こうとして失敗する |
| 6 | `APP_URL` = Railway の web の URL。**`APP_FRONTEND_URL` と `CORS_ALLOWED_ORIGINS` = Vercel の URL（末尾スラッシュなし）** | ブラウザで CORS エラーになる（API は動いているのに画面だけ失敗する） |
| 7 | Vercel の **Root Directory は `frontend`**。`VITE_API_BASE_URL` = Railway の URL + `/api` | ビルドに失敗する / フロントが別の API を向く |
| 8 | **`VITE_` の変数を変えたら、Vercel を再デプロイする**（ビルド時に JS へ埋め込まれるため） | 変数を直したのに、画面が古い API を向いたまま |
| 9 | **`APP_KEY` は新しく作る**（`php artisan key:generate --show`）。チャットやコミットに貼らない | ローカルや他の環境と同じキーを使い回すことになる |
| 10 | `/api/health` が **DB を確認する**（実装済み。DB につながらなければ 503） | DB の設定ミスに、デプロイ直後に気づけない |

**この 10 項目のうち、コードで防げるものは自動で検出します**（起動前の `app:deploy-check`、Vercel のビルド時の確認、デプロイ後のスモークテスト）。検出できないのは 1・2・4・5 の「ダッシュボードの設定」だけで、4 章の手順どおりに行ってください。

### 自動で検出する仕組み

| 仕組み | いつ | 何を見るか |
|---|---|---|
| `php artisan app:deploy-check`（web は `--wait-db=60`、worker は `--worker`） | Railway のコンテナの起動時（`start-web.sh` と worker の起動コマンドの先頭） | `APP_KEY` の形式 / `APP_DEBUG=false` / `APP_URL` が https でローカルでない / DB が mysql で `DB_HOST` がローカルでなく `DB_PASSWORD` が空でない / `CORS_ALLOWED_ORIGINS` が空・ワイルドカード・末尾スラッシュ・ローカル・http でない / Pusher を使うならキー・cluster がそろっている。DB につながるまで最大 60 秒待つ。**問題があれば、日本語で直し方を表示して起動を止める**（秘密の値は表示しない） |
| `frontend/scripts/check-deploy-env.mjs` | Vercel のビルド時（`VERCEL=1`） | `VITE_API_BASE_URL` が未設定・http・localhost・末尾スラッシュ・`/api` なし / Pusher のキーだけで cluster がない。**問題があればビルドを止める** |
| `bash scripts/smoke-test.sh <API の URL> [Vercel の URL]` | デプロイ後（人が実行） | 6 章 |
| GitHub Actions `Docker image` | PR・push（`backend/` を変えたとき） | 本番の Dockerfile をビルドし、MySQL 8 と一緒に web・worker を起動して、スモークテストを流す |

## 3. 事前に用意するもの

- GitHub のリポジトリ `KakutaSho28/courtq`（Railway と Vercel に GitHub の連携を許可します）
- アカウント: Railway / Vercel / Pusher（Pusher は任意）
- 手元の端末（`APP_KEY` の生成とスモークテストの実行に使う）。`php` が無ければ `openssl` と `bash` で足ります

## 4. ダッシュボードの手順（この順番で）

> 各手順の「**確認**」に合えば次へ進みます。合わないときは 8 章の「症状から探す」を見てください。

### 手順 0: 手元で `APP_KEY` を作る

次のどちらかを実行して、`base64:` で始まる 1 行を**手元のメモに控える**（チャットに貼らない）。

```bash
# A) PHP がある場合
cd backend && php artisan key:generate --show

# B) PHP が無い場合（同じ形式: base64: + 32 バイト）
echo "base64:$(openssl rand -base64 32)"
```

### 手順 1: Pusher のアプリを作る（任意。リアルタイム更新を使うなら）

1. https://dashboard.pusher.com/ → **Channels** → **Create app**
2. Name: `courtq`、Cluster: 利用者に近いもの（日本なら `ap3`（Tokyo））、Front end: React、Back end: Laravel → **Create app**
3. **App Keys** の 4 つ（`app_id` / `key` / `secret` / `cluster`）を手元に控える
4. 設定は既定のままで構いません（このアプリは**公開チャンネルだけ**を使い、チャンネルの認証は使いません）

**確認**: App Keys の画面に 4 つの値が表示されている。

> Pusher を使わない場合は、この手順と、以降の `PUSHER_*` / `BROADCAST_DRIVER` / `VITE_PUSHER_*` を省略します。画面は 30 秒ごとの取り直しで動きます。

### 手順 2: Railway にプロジェクトを作る（MySQL と web）

1. https://railway.com/ → **New Project** → **Deploy from GitHub repo** → `courtq` を選ぶ（初回は GitHub の連携を許可）
   - 最初に作られるサービスが **web** になります。サービスの名前を `web` に変えます。
   - 最初は `develop` ブランチで試す場合: web の **Settings → Source → Branch** を `develop` にします（本番にするときに `main` へ変更）。
2. **+ New** → **Database** → **Add MySQL**。サービスの名前は **`MySQL` のまま**にします（参照変数 `${{MySQL.…}}` の名前と一致させるため）。
   - **MySQL サービスの Settings → Networking で、公開ドメイン・TCP プロキシを作らない**（何も作らない）。
3. **web** を開き、**Settings** を設定する:
   - **Root Directory**: `/backend`
   - **Builder**: Dockerfile（`backend/railway.json` で指定済み。自動で選ばれます）
   - **Networking → Generate Domain**: ドメインを生成します。「ターゲットポート」を聞かれたら **`8080`**（次の手順の `PORT` と同じ値）
4. **web → Variables** を開き、**5 章の「web の環境変数」の表**のとおりに設定します（**Raw Editor** に貼り付けると速いです）。
   - **このときまだ決まっていない値**: `CORS_ALLOWED_ORIGINS` と `APP_FRONTEND_URL` は、Vercel の URL が決まる手順 4 で設定します。**いまは仮に空けておいてください**。
   - `APP_URL` には、手順 3 で生成した Railway のドメイン（`https://〜.up.railway.app`、末尾スラッシュなし）を入れます。
   - **Variables 画面の上に、Railway が「変数を提案」するバナーが出ても、「Add」を押さないでください**（ローカル開発用の値です）。
5. 保存すると、Railway が自動でデプロイを始めます。

**確認（この時点では、デプロイの失敗が正しい動作です）**: **Deployments → Deploy Logs** に、次のような日本語のメッセージが出て止まる。

```
[エラー] CORS_ALLOWED_ORIGINS が空です。Vercel の URL（…）を設定してください。…
```

出ているのが `APP_KEY` / `DB_` / `APP_URL` のエラーなら、5 章の表と見比べて直してください（直すと自動で再デプロイされます）。`CORS_ALLOWED_ORIGINS` と `APP_FRONTEND_URL` **以外**のエラーが消えれば、次へ進みます。

### 手順 3: Vercel にフロントをデプロイする

1. https://vercel.com/ → **Add New… → Project** → `courtq` を **Import**
2. **Root Directory**: `frontend`（**Edit** で選ぶ）。**Framework Preset**: Vite（自動）
3. **Environment Variables** に、5 章の「Vercel の環境変数」の表のとおりに設定する:
   - `VITE_API_BASE_URL` = **手順 2 の Railway の URL + `/api`**（例: `https://courtq-api-production.up.railway.app/api`。末尾スラッシュなし）
   - `VITE_PUSHER_APP_KEY` / `VITE_PUSHER_APP_CLUSTER`（Pusher を使う場合）
4. **Deploy**

**確認**: ビルドが成功し、`https://〜.vercel.app` が開いてログイン画面が出る（この時点では API が CORS で拒否されるので、ログインはまだできません）。ビルドに失敗して **「環境変数の確認でエラーがあります」** と出たら、表示された変数を直して **Redeploy** します。

この **Vercel の URL**（`https://〜.vercel.app`、末尾スラッシュなし）を控えます。

### 手順 4: Railway に Vercel の URL を設定する

1. Railway の **web → Variables** で、次の 2 つを **Vercel の URL**（末尾スラッシュなし）にする:
   - `CORS_ALLOWED_ORIGINS`
   - `APP_FRONTEND_URL`
2. 保存すると、web が自動で再デプロイされる

**確認**: **Deploy Logs** に次が出て、デプロイが **Active** になる。

```
設定の確認: 問題はありません。
… migrate（初回は全マイグレーションが DONE）…
```

ブラウザで `https://<Railway のドメイン>/api/health` を開くと `{"data":{"status":"ok","database":"ok"}}` が返る。

### 手順 5: worker を追加する

1. Railway のプロジェクトで **+ New** → **GitHub Repo** → `courtq`。サービスの名前を `worker` にする。
2. **worker → Settings**:
   - **Root Directory**: `/backend`
   - **Config as Code（Railway Config File）のパス**: `/backend/railway.worker.json`（リポジトリのルートからの絶対パスで指定します）
   - **Networking**: ドメインを**作らない**（worker は HTTP を受けません）
3. **worker → Variables** に、5 章の「worker の環境変数」の表を設定する（web の値を `${{web.…}}` で参照します）。
4. 保存すると、自動でデプロイされる

**確認**: worker の **Deploy Logs** に `設定の確認: 問題はありません。` が出て、その後エラーなく動き続ける（ログが静かなのは正常です。ジョブが来たときだけ `App\Events\… DONE` と出ます）。

> web の `QUEUE_CONNECTION=database` と、worker が同じ MySQL を見ていることが、リアルタイム配信の条件です。worker が無いと、混雑・在庫の更新は保存されますが、リアルタイムの配信は行われません。

### 手順 6: デプロイ後の確認（手元で）

```bash
bash scripts/smoke-test.sh https://<Railway のドメイン>/api https://<Vercel の URL>
```

**確認**: 最後に `All smoke checks passed.` が出る（6 章）。失敗したときは、出力に「何を直すか」が書いてあります。

### 手順 7: 実機で確認する

1. スマホで Vercel の URL を開き、**デモアカウントでログイン**（README のデモアカウント）→ 本日の試合 → ブース一覧
2. もう 1 台（または PC）で、スタッフ（`staff1@example.com`）でログインし、混雑を更新する
3. **確認**: 1 台目の画面が、再読み込みなしで更新される。ヘッダーに「リアルタイム接続中」が出る（Pusher を設定した場合）
4. スマホで「ホーム画面に追加」できる（PWA）

### 手順 8: 本番の運用の準備

1. **デモデータとパスワード**: `SEED_DEMO=true` の間、起動のたびにデモデータ（架空の会場・試合・ブース・5 人のアカウント）を投入します。**デモのパスワードは README に書いてある公開の値**なので、公開するなら次のどちらかにします。
   - 実運用のデータを入れない公開デモ: `DEMO_PASSWORD` を、自分だけが知る値に変える（変えたあとは、デモのアカウントでログインするときその値を使う。**既存のアカウントのパスワードは変わりません**ので、`SEED_DEMO` を一度 `false` にして DB を作り直すか、アカウントを削除します）
   - 実運用: `SEED_DEMO` を **`false`**（または削除）にして、管理者を作る（次の項目）
2. **管理者の作成**: Railway の **web** で、コンテナにコマンドを実行します（Railway CLI の `railway ssh`、またはダッシュボードのシェル）。
   ```bash
   php artisan app:create-admin <メールアドレス>
   ```
   新規の場合、パスワードが **1 回だけ**表示されます（控えてください）。
3. `main` ブランチへ切り替える（手順 2 で `develop` にした場合）: web・worker の **Settings → Source → Branch** を `main` に、Vercel の **Production Branch** を `main` にする。

## 5. 環境変数の表

`backend/.env.example` と `frontend/.env.example` が、ローカル開発用の値です。**本番の値とは別物**です（コピーしないでください）。

### web の環境変数（Railway）

| 変数 | 値 | 必須 | 説明 |
|---|---|---|---|
| `PORT` | `8080` | ✓ | nginx の待ち受けポート。公開ドメインの**ターゲットポートと同じ値** |
| `APP_KEY` | 手順 0 の値（`base64:…`） | ✓ | 秘密。新しく作る |
| `APP_ENV` | `production` | ✓ | Dockerfile でも設定済み。念のため明示 |
| `APP_DEBUG` | `false` | ✓ | `true` だと起動しません |
| `APP_URL` | `https://<web のドメイン>` | ✓ | 末尾スラッシュなし。https |
| `APP_FRONTEND_URL` | `https://<Vercel の URL>` | ✓ | 末尾スラッシュなし |
| `CORS_ALLOWED_ORIGINS` | `https://<Vercel の URL>` | ✓ | **末尾スラッシュなし**。ワイルドカード不可。カンマ区切りで複数可 |
| `CORS_ALLOWED_ORIGINS_PATTERNS` | 例: `#^https://courtq-.*\.vercel\.app$#` | – | Vercel のプレビュー URL も許可するときだけ（正規表現） |
| `DB_CONNECTION` | `mysql` | ✓ | |
| `DB_HOST` | `${{MySQL.MYSQLHOST}}` | ✓ | **参照変数**。`MySQL` は MySQL サービスの名前と同じにする |
| `DB_PORT` | `${{MySQL.MYSQLPORT}}` | ✓ | 参照変数 |
| `DB_DATABASE` | `${{MySQL.MYSQLDATABASE}}` | ✓ | 参照変数 |
| `DB_USERNAME` | `${{MySQL.MYSQLUSER}}` | ✓ | 参照変数 |
| `DB_PASSWORD` | `${{MySQL.MYSQLPASSWORD}}` | ✓ | 参照変数。**空にしない** |
| `CACHE_DRIVER` | `database` | ✓ | ブース一覧の短いキャッシュとレート制限。`cache` テーブルは migrate で作られる |
| `QUEUE_CONNECTION` | `database` | ✓ | リアルタイム配信をキューに積む。worker が処理する |
| `BROADCAST_DRIVER` | `pusher` | Pusher を使うとき | 使わないなら省略（配信しない） |
| `PUSHER_APP_ID` | Pusher の `app_id` | Pusher を使うとき | 秘密ではないが、控えは手元だけに |
| `PUSHER_APP_KEY` | Pusher の `key` | Pusher を使うとき | フロントの `VITE_PUSHER_APP_KEY` と同じ値 |
| `PUSHER_APP_SECRET` | Pusher の `secret` | Pusher を使うとき | **秘密** |
| `PUSHER_APP_CLUSTER` | 例: `ap3` | Pusher を使うとき | フロントの `VITE_PUSHER_APP_CLUSTER` と同じ値 |
| `SEED_DEMO` | `true` / `false` | – | `true` だと起動のたびにデモデータを投入（冪等）。運用では `false` |
| `DEMO_PASSWORD` | 任意 | – | デモアカウントのパスワード（既定は README の公開の値） |
| `COURTQ_STALE_AFTER_MINUTES` | 既定 `15` | – | 「情報が古い可能性があります」を出すまでの分数 |
| `COURTQ_LIST_CACHE_SECONDS` | 既定 `20` | – | ブース一覧のサーバーキャッシュ（10〜30 秒） |
| `COURTQ_INVITATION_TTL_DAYS` | 既定 `7` | – | 招待コードの有効日数 |
| `LOG_LEVEL` | 既定 `debug` | – | 運用では `info` 推奨 |

> `LOG_CHANNEL=stderr` は Dockerfile で設定済みです（Railway のログに出ます）。`DB_PASSWORD` などの秘密は、ログに出ません。

### worker の環境変数（Railway）

web と同じ値を、`${{web.変数名}}` で参照します（web の値を変えると、worker にも反映されます）。

| 変数 | 値 |
|---|---|
| `APP_KEY` | `${{web.APP_KEY}}` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `DB_CONNECTION` | `mysql` |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | web と同じ参照変数（`${{MySQL.MYSQLHOST}}` など） |
| `CACHE_DRIVER` | `database` |
| `QUEUE_CONNECTION` | `database` |
| `BROADCAST_DRIVER` | `${{web.BROADCAST_DRIVER}}` |
| `PUSHER_APP_ID` / `PUSHER_APP_KEY` / `PUSHER_APP_SECRET` / `PUSHER_APP_CLUSTER` | `${{web.PUSHER_APP_ID}}` など（Pusher を使うとき） |

`PORT`・`APP_URL`・`CORS_*` は worker には要りません（HTTP を受けないため。`app:deploy-check --worker` も見ません）。

### MySQL（Railway）

変数の設定は不要です（Railway が `MYSQLHOST` / `MYSQLPORT` / `MYSQLDATABASE` / `MYSQLUSER` / `MYSQLPASSWORD` を自動で作ります）。**公開ドメイン・TCP プロキシは作らない**。

### Vercel の環境変数

| 変数 | 値 | 必須 | 説明 |
|---|---|---|---|
| `VITE_API_BASE_URL` | `https://<web のドメイン>/api` | ✓ | **末尾スラッシュなし、末尾は `/api`**。変更したら**再デプロイ** |
| `VITE_PUSHER_APP_KEY` | Pusher の `key` | Pusher を使うとき | 未設定ならリアルタイムは無効（30 秒ごとの取り直しで動く） |
| `VITE_PUSHER_APP_CLUSTER` | 例: `ap3` | Pusher のキーを入れたとき | |
| `VITE_APP_NAME` | 既定 `CourtQ` | – | アプリ名（画面・タイトル・マニフェスト） |

Vercel のプロジェクト設定: **Root Directory** = `frontend` / **Framework Preset** = Vite / **Build Command** = `npm run build` / **Output Directory** = `dist`（`frontend/vercel.json` にも書いてあります）。SPA の直接アクセス（`/games/1` など）は、`frontend/vercel.json` の rewrite で `index.html` に返します。

## 6. スモークテスト（デプロイ後の確認）

```bash
bash scripts/smoke-test.sh https://<Railway のドメイン>/api https://<Vercel の URL>
```

| 確認 | 失敗したとき、主に疑う場所 |
|---|---|
| API の URL が https で、末尾が `/api` | 引数の指定ミス |
| `/api/health` が JSON で返り、`database` が `ok` | Root Directory が `/backend` でない / `PORT` とドメインのポートが違う / DB の参照変数 |
| 未ログインは 401、存在しない URL は JSON の 404 | |
| 登録 → ログイン → `/me` → 試合・ブース・お気に入り | デモデータが無い環境（`SEED_DEMO` が false で空）では、ブースの確認を飛ばします |
| **一般ユーザーが書き込み API を直接叩くと 403**（9 本） | 認可の不具合（あってはならない） |
| ログアウト後のトークンは 401 | |
| **CORS**: 許可した Vercel の URL が通る / プリフライトのキャッシュ（1 時間）/ 実際のリクエストにも返る / 他の Origin に `*` や相手の Origin を返さない | `CORS_ALLOWED_ORIGINS`（末尾スラッシュ・`https`）。直したら web を再デプロイ |
| フロントの `/`・`/login`・`/games/1`・`/favorites` が HTML で 200（SPA の rewrite） | Vercel の Root Directory / `vercel.json` |
| **ビルドされたフロントが、この API の URL を向いている** | `VITE_API_BASE_URL` を直して**再デプロイ**していない |
| PWA: `manifest.webmanifest` / `sw.js`（長くキャッシュされない）/ アイコン | |

> 実行のたびに、確認用の一般ユーザー（`smoke-<時刻>@example.com`）が 1 件登録されます（削除する API はありません）。本番のデータを変える操作（混雑・在庫の更新）は行いません。

## 7. 更新・ロールバック

- **更新**: `main` へ push（または PR をマージ）すると、Railway（web・worker）と Vercel が自動で再デプロイします。起動のたびに `app:deploy-check` → `migrate --force` → `config:cache` → `route:cache` が走ります。
- **ロールバック**: Railway の **Deployments** で、前のデプロイの **Redeploy**。Vercel の **Deployments** で、前のデプロイの **Promote to Production**。マイグレーションは自動では戻りません（このプロジェクトのマイグレーションは、追加のみで破壊的な変更はしない方針です）。
- **環境変数を変えたとき**: Railway は変更後に自動で再デプロイします。Vercel は**手動で Redeploy**（`VITE_` の変数はビルド時に埋め込まれるため）。
- **ログ**: Railway の web・worker の **Deploy Logs / Logs**（`LOG_CHANNEL=stderr`）。

## 8. 症状から探す

| 症状 | 原因 | 直し方 |
|---|---|---|
| web が `[エラー] …` を出して起動しない | 環境変数の取り違え（`app:deploy-check` が検出） | 表示された変数を、5 章の表のとおりに直す（自動で再デプロイ） |
| web が `DB に接続できません` | MySQL が起動していない / 参照変数の名前が違う（`${{MySQL.…}}` の `MySQL` が実際のサービス名と違う） | MySQL サービスが Active か、サービスの名前を確認 |
| デプロイは成功しているのに、ドメインが 502 | `PORT` と、公開ドメインのターゲットポートが違う | 両方を `8080` にそろえる |
| ビルドで `Dockerfile` が見つからない | Root Directory が `/backend` でない | web・worker の Root Directory を `/backend` にする |
| ブラウザのコンソールに **CORS エラー**（API 自体は動いている） | `CORS_ALLOWED_ORIGINS` が Vercel の URL と一致しない（末尾スラッシュ・`http`・別のプレビュー URL） | 末尾スラッシュなしの `https://〜.vercel.app` にして web を再デプロイ。プレビュー URL を使うなら `CORS_ALLOWED_ORIGINS_PATTERNS` |
| ログインできない（ネットワークエラー） | フロントが別の API を向いている | `VITE_API_BASE_URL` を直して Vercel を**再デプロイ**。スモークテストが「ビルドされたフロントが、この API の URL を向いていません」と教える |
| Vercel のビルドが「環境変数の確認でエラーがあります」で止まる | `VITE_API_BASE_URL` の未設定・末尾スラッシュ・`/api` なし | 表示された変数を直して Redeploy |
| 更新しても、他の端末の画面が変わらない | worker が動いていない / `QUEUE_CONNECTION` が `sync` のまま / Pusher のキーの不一致 / `BROADCAST_DRIVER` が `pusher` でない | worker の Logs を確認。web と worker の Pusher の値、フロントの `VITE_PUSHER_*` が同じか確認（フロントは再デプロイ）。**30 秒ごとの取り直しで、最終的には追いつく** |
| 「リアルタイム接続中」が出ない | `VITE_PUSHER_APP_KEY` が未設定 / cluster が違う | Vercel の変数を直して再デプロイ |
| 管理者でログインしたい | 管理者は画面・API から作れない | `php artisan app:create-admin <メール>`（4 章の手順 8） |
| 古い画面が表示され続ける（PWA） | Service Worker が古い | `sw.js` は長くキャッシュしない設定。再読み込み（アプリを閉じて開き直す）で更新される |

## 9. 確認の状況（事実だけ）

「実装・テスト済み」と「実環境で確認済み」を分けて書きます（2026-10-16 時点）。

| 項目 | 状態 |
|---|---|
| `app:deploy-check` の検出（20 種類の間違い、秘密を出さないこと、worker 用） | ✅ PHPUnit で確認。コンテナの中で、間違った設定の起動が日本語で止まることも確認 |
| Railway と同じ起動手順（環境変数の確認 → DB 待機 → migrate → デモデータ → `config:cache` → `route:cache` → 起動）を、**PHP 8.2 + MySQL 8.0.46** のコンテナで実行 | ✅ |
| 起動スクリプト（`start-web.sh`）+ nginx + php-fpm + supervisord を実際に動かし、`/api/health`・スモークテスト・nginx の設定（`.env` と `.git` は 403、6MB の本文は 413、`Server` のバージョン非表示）を確認 | ✅（Ubuntu + PHP 8.3 のホストで。本番イメージは Debian + PHP 8.2） |
| worker の起動コマンド（`railway.worker.json`）で、MySQL のキューのジョブが Pusher の HTTP API（記録用の偽のサーバー）に届くこと | ✅ |
| 12 件の同時更新（MySQL + 複数の php-fpm ワーカー）で、在庫の行が 1 件・履歴が 12 件・全部 200 | ✅ |
| スモークテストが、5 種類の設定ミス（再デプロイ忘れ・localhost のまま・CORS の不一致・`/api` なし・HTML が返る）を検出すること | ✅ |
| バックエンドの全テストを **MySQL 8.0** で実行（273 件 → 278 件） | ✅ |
| 本番の **Dockerfile を通しでビルド**、本番イメージ + MySQL 8 で web・worker を起動してスモークテスト | ✅ GitHub Actions の `Docker image`（`docker.yml`）で成功（PR #10、2026-10-17） |
| **Railway に実際にデプロイ**（Root Directory・ターゲットポート・参照変数・`railway.worker.json` のパス指定・ヘルスチェック） | **not verified**（Railway のアカウントがない） |
| **Vercel に実際にデプロイ**（Root Directory・環境変数・rewrite・ヘッダー） | **not verified** |
| **Pusher との実際の通信**（署名・cluster・TLS・接続数の上限） | **not verified** |
| Railway の自動提案（`.env.example` 由来の変数）が実際にどう表示されるか | **not verified**（`app:deploy-check` が、ローカルの値を検出して止める） |
