# API 仕様

> このファイルは自動生成です。直接編集せず、`backend/docs/api-examples.php` を更新して `cd backend && php artisan docs:api` を実行してください。
> ルート・パス・認証・バリデーションルールは `php artisan route:list --json` と FormRequest から取得しており、`ApiDocsTest` がコードとのずれを検知します。

## 共通仕様

- ベース URL: `https://<railway-domain>/api`（ローカル: `http://localhost/api`）
- 形式: JSON（`Accept: application/json`）。アップロードのみ `multipart/form-data`
- 認証: Laravel Sanctum の Bearer トークン。`POST /api/auth/register` または `POST /api/auth/login` で取得し、`Authorization: Bearer <token>` を付ける。「認証: 必要」のエンドポイントで未指定・無効なら 401
- レスポンスの形式: 成功は `{ "data": ... }`、メッセージのみは `{ "message": "..." }`。一覧（ページング）は `{ "data": [...], "meta": {...}, "links": {...} }`
- 日時: ISO 8601（例 `2026-10-10T10:00:00+09:00`）
- ページング: 1 ページ 20 件。`?page=` で指定

### 共通エラー

| ステータス | 意味 | 形式 |
|---|---|---|
| 401 | 未認証 | `{ "message": "Unauthenticated." }` |
| 403 | 権限なし | `{ "message": "この操作は許可されていません" }` |
| 404 | 対象が存在しない | `{ "message": "..." }` |
| 422 | 入力エラー | `{ "message": "...", "errors": { "<field>": ["..."] } }` |
| 429 | リクエスト過多（スロットリング） | `{ "message": "Too Many Attempts." }` |

### 権限の考え方

| 対象 | 閲覧・アノテーション・コメント・共有・切り抜き | 削除 |
|---|---|---|
| 個人動画 | 投稿者のみ | 投稿者のみ |
| チーム動画 | チームメンバー全員 | 投稿者、またはチームオーナー |
| コメント | 動画を閲覧できる人 | 投稿者本人のみ |
| チーム | メンバー全員が閲覧 | オーナーのみ（メンバーの削除はオーナー、脱退は本人） |

## エンドポイント一覧

### システム

| メソッド | パス | 認証 | 概要 |
|---|---|---|---|
| GET | `/api/health` | 不要 | ヘルスチェック |

### 認証

| メソッド | パス | 認証 | 概要 |
|---|---|---|---|
| POST | `/api/auth/register` | 不要 | ユーザー登録 |
| POST | `/api/auth/login` | 不要 | ログイン |
| POST | `/api/auth/logout` | 必要 | ログアウト |
| GET | `/api/me` | 必要 | ログイン中のユーザー |

---

## GET `/api/health`

**ヘルスチェック**

- 実装: `App\Http\Controllers\HealthController`
- 認証: 不要

DB に接続できるかを確認する。稼働監視と Railway の healthcheck が使う。DB に接続できないときは 503。

**レスポンス**

- `200` 正常

```json
{
    "data": {
        "status": "ok",
        "database": "ok"
    }
}
```

- `503` DB に接続できない

```json
{
    "data": {
        "status": "error",
        "database": "error"
    }
}
```


---

## POST `/api/auth/register`

**ユーザー登録**

- 実装: `App\Http\Controllers\AuthController@register`
- 認証: 不要

登録と同時にトークンを発行する。以降は `Authorization: Bearer <token>` を付ける。登録されるユーザーの役割は常に一般ユーザー（`role` はクライアントから受け取らない）。

**リクエストのバリデーション**（FormRequest から自動取得）

| フィールド | ルール |
|---|---|
| `name` | `required` `string` `max:100` |
| `email` | `required` `email` `unique:users,email` |
| `password` | `required` `string` `min:8` `confirmed` |

**リクエスト例**

```json
{
    "name": "アリス",
    "email": "alice@example.com",
    "password": "password123",
    "password_confirmation": "password123"
}
```

**レスポンス**

- `201` 登録成功

```json
{
    "data": {
        "user": {
            "id": 1,
            "name": "アリス",
            "email": "alice@example.com"
        },
        "token": "1|abcdef...（Sanctum のトークン）"
    }
}
```

- `422` 入力不正（メール重複など）

```json
{
    "message": "このメールアドレス はすでに使われています",
    "errors": {
        "email": [
            "このメールアドレス はすでに使われています"
        ]
    }
}
```

- `429` レート制限

```json
{
    "message": "リクエストが多すぎます。しばらくしてからもう一度お試しください"
}
```


---

## POST `/api/auth/login`

**ログイン**

- 実装: `App\Http\Controllers\AuthController@login`
- 認証: 不要

既存のトークンはすべて失効し、新しいトークンを発行する（多重ログイン防止）。ログイン処理は `AuthService` に集約しており、外部 ID プロバイダ（SSO / OAuth）を追加するときもここを拡張する。同じメール + IP からは 1 分に 10 回まで。

**リクエストのバリデーション**（FormRequest から自動取得）

| フィールド | ルール |
|---|---|
| `email` | `required` `email` |
| `password` | `required` `string` `min:8` |

**リクエスト例**

```json
{
    "email": "alice@example.com",
    "password": "password123"
}
```

**レスポンス**

- `200` ログイン成功

```json
{
    "data": {
        "user": {
            "id": 1,
            "name": "アリス",
            "email": "alice@example.com"
        },
        "token": "1|abcdef...（Sanctum のトークン）"
    }
}
```

- `401` メールアドレスまたはパスワードが違う

```json
{
    "message": "メールアドレスまたはパスワードが正しくありません"
}
```

- `422` 入力不正
- `429` レート制限

```json
{
    "message": "リクエストが多すぎます。しばらくしてからもう一度お試しください"
}
```


---

## POST `/api/auth/logout`

**ログアウト**

- 実装: `App\Http\Controllers\AuthController@logout`
- 認証: 必要（Bearer トークン）

現在のトークンを失効させる。

**レスポンス**

- `200` ログアウト成功

```json
{
    "message": "ログアウトしました"
}
```

- `401` 未ログイン / トークンが無効

```json
{
    "message": "ログインが必要です"
}
```


---

## GET `/api/me`

**ログイン中のユーザー**

- 実装: `App\Http\Controllers\AuthController@me`
- 認証: 必要（Bearer トークン）

役割（`role`）と担当ブースは WP-B4 で追加する。

**レスポンス**

- `200` ユーザー

```json
{
    "data": {
        "id": 1,
        "name": "アリス",
        "email": "alice@example.com"
    }
}
```

- `401` 未ログイン / トークンが無効

```json
{
    "message": "ログインが必要です"
}
```


---

## リアルタイムイベント（Pusher Channels）

REST ではなく WebSocket で配信される通知。試合（ゲーム）ごとに 1 つの**公開チャンネル** `game.{gameId}` を購読する（内容が機微でなく、閲覧者ごとの認証の負荷を避けるため。チャンネル認証 API は無い）。イベントはキュー経由で worker が送信する。

> イベントの一覧（混雑の更新・在庫の更新）は WP-B8 で実装し、この節に追記する。
