# API 仕様

> このファイルは自動生成です。直接編集せず、`backend/docs/api-examples.php` を更新して `cd backend && php artisan docs:api` を実行してください。
> ルート・パス・認証・バリデーションルールは `php artisan route:list --json` と FormRequest から取得しており、`ApiDocsTest` がコードとのずれを検知します。

## 共通仕様

- ベース URL: `https://<railway-domain>/api`（ローカル: `http://localhost/api`）
- 形式: JSON（`Accept: application/json`）。リクエストも JSON（CSV インポートのみ `multipart/form-data`）
- 認証: Laravel Sanctum の Bearer トークン。`POST /api/auth/register` または `POST /api/auth/login` で取得し、`Authorization: Bearer <token>` を付ける。「認証: 必要」のエンドポイントで未指定・無効なら 401
- レスポンスの形式: 成功は `{ "data": ... }`、メッセージのみは `{ "message": "..." }`。エラーのメッセージは日本語
- 日時: ISO 8601（例 `2026-10-10T10:00:00+09:00`）。混雑・在庫の値には最終更新時刻（`updated_at`）が付く

### 共通エラー

| ステータス | 意味 | 形式 |
|---|---|---|
| 401 | 未ログイン / トークン無効 | `{ "message": "ログインが必要です" }` |
| 403 | 権限なし | `{ "message": "この操作は許可されていません" }` |
| 404 | 対象が存在しない | `{ "message": "見つかりません" }` |
| 422 | 入力エラー | `{ "message": "<最初のエラー>", "errors": { "<field>": ["..."] } }` |
| 429 | リクエスト過多（スロットリング） | `{ "message": "リクエストが多すぎます。しばらくしてからもう一度お試しください" }` |

### 権限の考え方

役割（`role`）は `user`（既定）/ `staff` / `admin`。**サーバーが決めるもので、クライアントからは受け取らない**。混雑・在庫の更新は、認証 → ロール（`role:staff,admin`）→ ブースの担当判定（Policy）→ 入力検証 → レート制限の順にサーバー側で検査する（CORS は直接の API 呼び出しを防がないため、守りはサーバーの認可）。役割に応じた API は WP-B4 で追加し、この表を更新する。

| 操作 | user | staff | admin |
|---|---|---|---|
| 試合・ブース・メニュー・在庫・履歴の閲覧、お気に入り | ○ | ○ | ○ |
| 混雑・在庫の更新、メニュー管理 | – | 担当ブースのみ | 全ブース |
| 試合・ブースの作成/編集、招待コード発行、スタッフ解除 | – | – | ○ |

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
