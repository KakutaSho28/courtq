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

### 試合

| メソッド | パス | 認証 | 概要 |
|---|---|---|---|
| GET | `/api/games` | 必要 | 試合の一覧（GAME-01） |
| GET | `/api/games/{game}` | 必要 | 試合の詳細（GAME-02） |

### ブース

| メソッド | パス | 認証 | 概要 |
|---|---|---|---|
| GET | `/api/games/{game}/booths` | 必要 | 試合のブース一覧（BOOTH-01） |
| GET | `/api/games/{game}/booths/{booth}` | 必要 | ブースの詳細（BOOTH-02） |
| GET | `/api/games/{game}/booths/{booth}/congestion-history` | 必要 | 混雑の履歴（BOOTH-03） |

### お気に入り

| メソッド | パス | 認証 | 概要 |
|---|---|---|---|
| GET | `/api/me/favorites` | 必要 | お気に入りのブース（FAV-01） |
| PUT | `/api/booths/{booth}/favorite` | 必要 | お気に入りに追加（FAV-02） |
| DELETE | `/api/booths/{booth}/favorite` | 必要 | お気に入りから外す（FAV-03） |

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

## GET `/api/games`

**試合の一覧（GAME-01）**

- 実装: `App\Http\Controllers\GameController@index`
- 認証: 必要（Bearer トークン）

今日開催の試合が先頭で、あとは開始時刻の早い順（最大 100 件）。`date` を指定するとその日の試合だけ、`from` を指定するとその日以降の試合だけ。どちらも省略すると今日以降の試合。日付はアプリのタイムゾーン（日本時間）で扱う。

**クエリパラメータ**

| 名前 | 説明 |
|---|---|
| `date` | `2026-10-16` の形式。その日の試合だけ |
| `from` | `2026-10-01` の形式。その日以降の試合だけ（`date` があれば無視） |

**リクエストのバリデーション**（FormRequest から自動取得）

| フィールド | ルール |
|---|---|
| `date` | `nullable` `date_format:Y-m-d` |
| `from` | `nullable` `date_format:Y-m-d` |

**レスポンス**

- `200` 試合の一覧

```json
{
    "data": [
        {
            "id": 1,
            "season": "2026-27",
            "section": 5,
            "home_team": "ベイサイド・ダイナモ",
            "away_team": "シティ・ラインズ",
            "venue": {
                "id": 1,
                "name": "みなと第一アリーナ"
            },
            "tipoff_at": "2026-10-16T19:05:00+09:00",
            "status": "live"
        }
    ]
}
```

- `401` 未ログイン / トークンが無効

```json
{
    "message": "ログインが必要です"
}
```

- `422` 日付の形式が不正（Y-m-d）

---

## GET `/api/games/{game}`

**試合の詳細（GAME-02）**

- 実装: `App\Http\Controllers\GameController@show`
- 認証: 必要（Bearer トークン）

**パスパラメータ**: `game`

**レスポンス**

- `200` 試合

```json
{
    "data": {
        "id": 1,
        "season": "2026-27",
        "section": 5,
        "home_team": "ベイサイド・ダイナモ",
        "away_team": "シティ・ラインズ",
        "venue": {
            "id": 1,
            "name": "みなと第一アリーナ"
        },
        "tipoff_at": "2026-10-16T19:05:00+09:00",
        "status": "live"
    }
}
```

- `401` 未ログイン / トークンが無効

```json
{
    "message": "ログインが必要です"
}
```

- `404` 対象が存在しない

```json
{
    "message": "見つかりません"
}
```


---

## GET `/api/games/{game}/booths`

**試合のブース一覧（BOOTH-01）**

- 実装: `App\Http\Controllers\BoothController@index`
- 認証: 必要（Bearer トークン）

混雑（4 段階 + 待ち時間）と最終更新を含む軽い一覧。メニューは含まない（詳細で取る）。全員に共通の部分はサーバーで 20 秒（`COURTQ_LIST_CACHE_SECONDS`）キャッシュされ、混雑・在庫を更新すると破棄される。お気に入りの印と絞り込みはユーザーごとにキャッシュの外で付ける。

- `category`: `food` / `merch` は兼営（`both`）のブースも含む。`both` は兼営のブースだけ
- `sort`: `name`（既定）/ `congestion`（空いている順）/ `wait`（待ち時間の短い順）。どの並び方でも、営業中が先で閉店中は最後。混雑・待ち時間が未入力のブースは入力済みより後ろ。名前順は文字コード順（五十音順ではない）
- `in_stock=1`: 販売中のメニューに、売り切れでない在庫が 1 つ以上あるブースだけ（`has_stock` が true）
- `favorites=1`: 自分のお気に入りだけ
- `congestion.is_stale`: 最終更新から `meta.stale_after_minutes`（15 分）を超えたら true（「情報が古い可能性があります」を出す目印）。一度も更新されていないときは `level` などが null で、`is_stale` は false

**パスパラメータ**: `game`

**クエリパラメータ**

| 名前 | 説明 |
|---|---|
| `category` | `food` / `merch` / `both` |
| `sort` | `name`（既定）/ `congestion` / `wait` |
| `in_stock` | `1` で在庫のあるブースだけ |
| `favorites` | `1` で自分のお気に入りだけ |

**リクエストのバリデーション**（FormRequest から自動取得）

| フィールド | ルール |
|---|---|
| `category` | `nullable` `in:"food","merch","both"` |
| `sort` | `nullable` `in:congestion,wait,name` |
| `in_stock` | `nullable` `boolean` |
| `favorites` | `nullable` `boolean` |

**レスポンス**

- `200` ブースの一覧

```json
{
    "data": [
        {
            "booth_id": 1,
            "game_booth_id": 10,
            "name": "から揚げスタンド",
            "category": "food",
            "location_label": "2F コンコース東",
            "is_open": true,
            "congestion": {
                "level": 3,
                "label": "混雑",
                "wait_minutes": 25,
                "updated_at": "2026-10-16T19:27:00+09:00",
                "is_stale": false
            },
            "has_stock": true,
            "is_favorite": false
        }
    ],
    "meta": {
        "stale_after_minutes": 15
    }
}
```

- `401` 未ログイン / トークンが無効

```json
{
    "message": "ログインが必要です"
}
```

- `404` 対象が存在しない

```json
{
    "message": "見つかりません"
}
```

- `422` 絞り込み・並び順の指定が不正

---

## GET `/api/games/{game}/booths/{booth}`

**ブースの詳細（BOOTH-02）**

- 実装: `App\Http\Controllers\BoothController@show`
- 認証: 必要（Bearer トークン）

混雑と、販売中のメニュー（`kind` が `food` / `merch`。画面で飲食・物販に分ける）、その試合の在庫と最終更新。在庫がまだ登録されていないメニューは `stock` が null。そのブースがこの試合に出店していないときは 404。

**パスパラメータ**: `game`、`booth`

**レスポンス**

- `200` ブース

```json
{
    "data": {
        "booth_id": 1,
        "game_booth_id": 10,
        "name": "から揚げスタンド",
        "category": "food",
        "location_label": "2F コンコース東",
        "description": "揚げたてのから揚げとドリンク",
        "is_open": true,
        "congestion": {
            "level": 3,
            "label": "混雑",
            "wait_minutes": 25,
            "updated_at": "2026-10-16T19:27:00+09:00",
            "is_stale": false
        },
        "menu_items": [
            {
                "id": 1,
                "name": "から揚げ 5 個",
                "kind": "food",
                "price": 700,
                "description": null,
                "stock": {
                    "status": "low",
                    "label": "残りわずか",
                    "quantity": 4,
                    "updated_at": "2026-10-16T19:10:00+09:00",
                    "is_stale": true
                }
            }
        ],
        "is_favorite": false
    },
    "meta": {
        "stale_after_minutes": 15
    }
}
```

- `401` 未ログイン / トークンが無効

```json
{
    "message": "ログインが必要です"
}
```

- `404` 試合またはブースが存在しない / この試合に出店していない

```json
{
    "message": "この試合に出店していないブースです"
}
```


---

## GET `/api/games/{game}/booths/{booth}/congestion-history`

**混雑の履歴（BOOTH-03）**

- 実装: `App\Http\Controllers\BoothController@congestionHistory`
- 認証: 必要（Bearer トークン）

混雑の推移のグラフ用。古い順で、直近の最大 200 件。更新したユーザーは返さない。

**パスパラメータ**: `game`、`booth`

**レスポンス**

- `200` 履歴

```json
{
    "data": [
        {
            "level": 3,
            "label": "混雑",
            "wait_minutes": 25,
            "recorded_at": "2026-10-16T19:27:00+09:00"
        }
    ]
}
```

- `401` 未ログイン / トークンが無効

```json
{
    "message": "ログインが必要です"
}
```

- `404` 対象が存在しない

```json
{
    "message": "見つかりません"
}
```


---

## GET `/api/me/favorites`

**お気に入りのブース（FAV-01）**

- 実装: `App\Http\Controllers\FavoriteController@index`
- 認証: 必要（Bearer トークン）

自分のお気に入りだけを名前順で返す。試合ごとの混雑は、ブース一覧の `favorites=1` で取る。

**レスポンス**

- `200` お気に入り

```json
{
    "data": [
        {
            "booth_id": 1,
            "name": "から揚げスタンド",
            "category": "food",
            "location_label": "2F コンコース東"
        }
    ]
}
```

- `401` 未ログイン / トークンが無効

```json
{
    "message": "ログインが必要です"
}
```


---

## PUT `/api/booths/{booth}/favorite`

**お気に入りに追加（FAV-02）**

- 実装: `App\Http\Controllers\FavoriteController@store`
- 認証: 必要（Bearer トークン）

追加済みでも成功する（冪等）。どの役割でも使える。

**パスパラメータ**: `booth`

**レスポンス**

- `200` 追加した

```json
{
    "data": {
        "booth_id": 1,
        "is_favorite": true
    }
}
```

- `401` 未ログイン / トークンが無効

```json
{
    "message": "ログインが必要です"
}
```

- `404` 対象が存在しない

```json
{
    "message": "見つかりません"
}
```


---

## DELETE `/api/booths/{booth}/favorite`

**お気に入りから外す（FAV-03）**

- 実装: `App\Http\Controllers\FavoriteController@destroy`
- 認証: 必要（Bearer トークン）

外れていても成功する（冪等）。

**パスパラメータ**: `booth`

**レスポンス**

- `200` 外した

```json
{
    "data": {
        "booth_id": 1,
        "is_favorite": false
    }
}
```

- `401` 未ログイン / トークンが無効

```json
{
    "message": "ログインが必要です"
}
```

- `404` 対象が存在しない

```json
{
    "message": "見つかりません"
}
```


---

## リアルタイムイベント（Pusher Channels）

REST ではなく WebSocket で配信される通知。試合（ゲーム）ごとに 1 つの**公開チャンネル** `game.{gameId}` を購読する（内容が機微でなく、閲覧者ごとの認証の負荷を避けるため。チャンネル認証 API は無い）。イベントはキュー経由で worker が送信する。

> イベントの一覧（混雑の更新・在庫の更新）は WP-B8 で実装し、この節に追記する。
