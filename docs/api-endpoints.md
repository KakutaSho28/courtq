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

役割（`role`）は `user`（既定）/ `staff` / `admin`。**サーバーが決めるもので、クライアントからは受け取らない**。混雑・在庫の更新は、認証（401）→ 役割（`role:staff,admin`、403）→ レート制限（429）→ ID の解決（404）→ ブースの担当判定（Policy、403）→ 入力検証（422）の順にサーバー側で検査する。役割の確認は ID の解決より前なので、一般ユーザーは存在しない ID を送っても 403 になる（CORS は直接の API 呼び出しを防がないため、守りはサーバーの認可）。各エンドポイントの「権限」は、ルートのミドルウェア（`role:` / `can:manage` / `throttle:`）から自動で書き出している。一般ユーザーが自分のトークンで書き込み API を直接呼んでも 403 になることを、全書き込み API についてテスト（`AuthorizationTest`）で確認している。

| 操作 | user | staff | admin |
|---|---|---|---|
| 試合・ブース・メニュー・在庫・履歴の閲覧、お気に入り | ○ | ○ | ○ |
| 混雑・在庫の更新、メニュー管理 | – | 担当ブースのみ | 全ブース |
| 試合・ブースの作成/編集、招待コード発行、スタッフ解除 | – | – | ○ |

## エンドポイント一覧

### システム

| メソッド | パス | 権限 | 概要 |
|---|---|---|---|
| GET | `/api/health` | 誰でも | ヘルスチェック |

### 認証

| メソッド | パス | 権限 | 概要 |
|---|---|---|---|
| POST | `/api/auth/register` | 誰でも | ユーザー登録 |
| POST | `/api/auth/login` | 誰でも | ログイン |
| POST | `/api/auth/logout` | ログインユーザー全員 | ログアウト |
| GET | `/api/me` | ログインユーザー全員 | ログイン中のユーザー |

### 試合

| メソッド | パス | 権限 | 概要 |
|---|---|---|---|
| GET | `/api/games` | ログインユーザー全員 | 試合の一覧（GAME-01） |
| GET | `/api/games/{game}` | ログインユーザー全員 | 試合の詳細（GAME-02） |

### ブース

| メソッド | パス | 権限 | 概要 |
|---|---|---|---|
| GET | `/api/games/{game}/booths` | ログインユーザー全員 | 試合のブース一覧（BOOTH-01） |
| GET | `/api/games/{game}/booths/{booth}` | ログインユーザー全員 | ブースの詳細（BOOTH-02） |
| GET | `/api/games/{game}/booths/{booth}/congestion-history` | ログインユーザー全員 | 混雑の履歴（BOOTH-03） |

### お気に入り

| メソッド | パス | 権限 | 概要 |
|---|---|---|---|
| GET | `/api/me/favorites` | ログインユーザー全員 | お気に入りのブース（FAV-01） |
| PUT | `/api/booths/{booth}/favorite` | ログインユーザー全員 | お気に入りに追加（FAV-02） |
| DELETE | `/api/booths/{booth}/favorite` | ログインユーザー全員 | お気に入りから外す（FAV-03） |

### スタッフ

| メソッド | パス | 権限 | 概要 |
|---|---|---|---|
| GET | `/api/staff/booths` | staff, admin | 管理できるブース（STAFF-01） |
| PUT | `/api/staff/games/{game}/booths/{booth}/congestion` | staff, admin・担当ブースのみ（admin は全ブース）（30 回/分/ユーザー） | 混雑の更新（STAFF-02） |
| PUT | `/api/staff/games/{game}/menu-items/{menuItem}/stock` | staff, admin・担当ブースのみ（admin は全ブース）（30 回/分/ユーザー） | 在庫の更新（STAFF-03） |
| GET | `/api/staff/booths/{booth}/menu-items` | staff, admin・担当ブースのみ（admin は全ブース） | メニューの一覧（STAFF-04） |
| POST | `/api/staff/booths/{booth}/menu-items` | staff, admin・担当ブースのみ（admin は全ブース） | メニューの追加（STAFF-04） |
| PUT | `/api/staff/booths/{booth}/menu-items/{menuItem}` | staff, admin・担当ブースのみ（admin は全ブース） | メニューの変更（STAFF-04） |
| DELETE | `/api/staff/booths/{booth}/menu-items/{menuItem}` | staff, admin・担当ブースのみ（admin は全ブース） | メニューの削除（STAFF-04） |
| POST | `/api/staff/invitations/accept` | ログインユーザー全員（10 回/分/ユーザー） | 招待コードを使う（STAFF-05） |

### 管理者

| メソッド | パス | 権限 | 概要 |
|---|---|---|---|
| POST | `/api/admin/booths/{booth}/invitations` | admin | 招待コードの発行（ADMIN-05） |
| GET | `/api/admin/booths/{booth}/staff` | admin | ブースの担当スタッフ（ADMIN-06） |
| DELETE | `/api/admin/booths/{booth}/staff/{user}` | admin | 担当の解除（ADMIN-06） |

---

## GET `/api/health`

**ヘルスチェック**

- 実装: `App\Http\Controllers\HealthController`
- 認証: 不要
- 権限: 誰でも

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
- 権限: 誰でも

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
            "email": "alice@example.com",
            "role": "user",
            "booths": []
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
- 権限: 誰でも

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
            "email": "alice@example.com",
            "role": "user",
            "booths": []
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
- 権限: ログインユーザー全員

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
- 権限: ログインユーザー全員

`role` は `user` / `staff` / `admin`。`booths` は担当登録（`booth_staff`）されたブース。admin は全ブースを管理できるが、`booths` には担当登録したブースだけが入る。

**レスポンス**

- `200` ユーザー

```json
{
    "data": {
        "id": 2,
        "name": "スタッフ サクラ",
        "email": "staff1@example.com",
        "role": "staff",
        "booths": [
            {
                "booth_id": 1,
                "name": "から揚げスタンド"
            }
        ]
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
- 権限: ログインユーザー全員

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
- 権限: ログインユーザー全員

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
- 権限: ログインユーザー全員

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
- 権限: ログインユーザー全員

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
- 権限: ログインユーザー全員

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
- 権限: ログインユーザー全員

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
- 権限: ログインユーザー全員

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
- 権限: ログインユーザー全員

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

## GET `/api/staff/booths`

**管理できるブース（STAFF-01）**

- 実装: `App\Http\Controllers\StaffBoothController@index`
- 認証: 必要（Bearer トークン）
- 権限: staff, admin

staff は担当ブースだけ、admin は全ブース。各ブースに、今日以降の出店予定を開始時刻の早い順で最大 3 件と、その試合の混雑の現在値を付ける（スタッフ画面から今日の試合の更新画面をすぐ開けるように）。

**レスポンス**

- `200` ブースの一覧

```json
{
    "data": [
        {
            "booth_id": 1,
            "name": "から揚げスタンド",
            "category": "food",
            "location_label": "2F コンコース東",
            "upcoming_games": [
                {
                    "game_id": 1,
                    "game_booth_id": 10,
                    "home_team": "ベイサイド・ダイナモ",
                    "away_team": "シティ・ラインズ",
                    "tipoff_at": "2026-10-16T19:05:00+09:00",
                    "status": "live",
                    "is_open": true,
                    "congestion": {
                        "level": 3,
                        "label": "混雑",
                        "wait_minutes": 25,
                        "updated_at": "2026-10-16T19:27:00+09:00",
                        "is_stale": false
                    }
                }
            ]
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

- `403` 役割が足りない / 担当ブースではない

```json
{
    "message": "この操作は許可されていません"
}
```


---

## PUT `/api/staff/games/{game}/booths/{booth}/congestion`

**混雑の更新（STAFF-02）**

- 実装: `App\Http\Controllers\StaffStatusController@updateCongestion`
- 認証: 必要（Bearer トークン）
- 権限: staff, admin・担当ブースのみ（admin は全ブース）（30 回/分/ユーザー）

担当ブースの混雑（4 段階）と待ち時間を更新する。現在値を書き換え、`congestion_logs` に 1 行追加し、ブース一覧のキャッシュを捨てる。一般ユーザー・他のブースのスタッフは 403（入力が不正でも 403 が先）。そのブースがこの試合に出店していなければ 404。

**パスパラメータ**: `game`、`booth`

**リクエストのバリデーション**（FormRequest から自動取得）

| フィールド | ルール |
|---|---|
| `level` | `required` `integer` `in:"1","2","3","4"` |
| `wait_minutes` | `nullable` `integer` `min:0` `max:180` |

**リクエスト例**

```json
{
    "level": 3,
    "wait_minutes": 25
}
```

**レスポンス**

- `200` 更新した

```json
{
    "data": {
        "game_id": 1,
        "booth_id": 1,
        "game_booth_id": 10,
        "is_open": true,
        "congestion": {
            "level": 3,
            "label": "混雑",
            "wait_minutes": 25,
            "updated_at": "2026-10-16T19:27:00+09:00",
            "is_stale": false
        }
    }
}
```

- `401` 未ログイン / トークンが無効

```json
{
    "message": "ログインが必要です"
}
```

- `403` 役割が足りない / 担当ブースではない

```json
{
    "message": "この操作は許可されていません"
}
```

- `404` 試合・ブースが存在しない / この試合に出店していない

```json
{
    "message": "この試合に出店していないブースです"
}
```

- `422` 入力不正（level は 1〜4、wait_minutes は 0〜180 または null）
- `429` レート制限

```json
{
    "message": "リクエストが多すぎます。しばらくしてからもう一度お試しください"
}
```


---

## PUT `/api/staff/games/{game}/menu-items/{menuItem}/stock`

**在庫の更新（STAFF-03）**

- 実装: `App\Http\Controllers\StaffStatusController@updateStock`
- 認証: 必要（Bearer トークン）
- 権限: staff, admin・担当ブースのみ（admin は全ブース）（30 回/分/ユーザー）

担当ブースのメニューの、この試合の在庫を作成または更新する（試合 × メニューで 1 行）。`stock_logs` に 1 行追加し、ブース一覧のキャッシュを捨てる。同じ値を送っても最終更新（`updated_at`）は新しくなる（「いま確認した」ことを伝えるため）。そのメニューのブースがこの試合に出店していなければ 404。

**パスパラメータ**: `game`、`menuItem`

**リクエストのバリデーション**（FormRequest から自動取得）

| フィールド | ルール |
|---|---|
| `status` | `required` `string` `in:"in_stock","low","sold_out"` |
| `quantity` | `nullable` `integer` `min:0` `max:99999` |

**リクエスト例**

```json
{
    "status": "low",
    "quantity": 4
}
```

**レスポンス**

- `200` 更新した

```json
{
    "data": {
        "game_id": 1,
        "menu_item_id": 1,
        "stock": {
            "status": "low",
            "label": "残りわずか",
            "quantity": 4,
            "updated_at": "2026-10-16T19:30:00+09:00",
            "is_stale": false
        }
    }
}
```

- `401` 未ログイン / トークンが無効

```json
{
    "message": "ログインが必要です"
}
```

- `403` 役割が足りない / 担当ブースではない

```json
{
    "message": "この操作は許可されていません"
}
```

- `404` 試合・メニューが存在しない / この試合に出店していない

```json
{
    "message": "この試合に出店していないブースのメニューです"
}
```

- `422` 入力不正（status は in_stock / low / sold_out、quantity は 0 以上の整数または null）
- `429` レート制限

```json
{
    "message": "リクエストが多すぎます。しばらくしてからもう一度お試しください"
}
```


---

## GET `/api/staff/booths/{booth}/menu-items`

**メニューの一覧（STAFF-04）**

- 実装: `App\Http\Controllers\StaffMenuItemController@index`
- 認証: 必要（Bearer トークン）
- 権限: staff, admin・担当ブースのみ（admin は全ブース）

担当ブースのメニューを、販売終了（`is_active: false`）のものも含めて返す（来場者向けのブース詳細は販売中だけ。スタッフが販売を再開できるように）。`kind`、`id` の順。

**パスパラメータ**: `booth`

**レスポンス**

- `200` メニューの一覧

```json
{
    "data": [
        {
            "id": 1,
            "booth_id": 1,
            "name": "から揚げ 5 個",
            "kind": "food",
            "price": 700,
            "description": null,
            "is_active": true
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

- `403` 役割が足りない / 担当ブースではない

```json
{
    "message": "この操作は許可されていません"
}
```

- `404` 対象が存在しない

```json
{
    "message": "見つかりません"
}
```


---

## POST `/api/staff/booths/{booth}/menu-items`

**メニューの追加（STAFF-04）**

- 実装: `App\Http\Controllers\StaffMenuItemController@store`
- 認証: 必要（Bearer トークン）
- 権限: staff, admin・担当ブースのみ（admin は全ブース）

担当ブースにメニューを追加する。`is_active` を省略すると販売中。

**パスパラメータ**: `booth`

**リクエストのバリデーション**（FormRequest から自動取得）

| フィールド | ルール |
|---|---|
| `name` | `required` `string` `max:100` |
| `kind` | `required` `string` `in:"food","merch"` |
| `price` | `required` `integer` `min:0` `max:1000000` |
| `description` | `nullable` `string` `max:500` |
| `is_active` | `sometimes` `boolean` |

**リクエスト例**

```json
{
    "name": "から揚げ 5 個",
    "kind": "food",
    "price": 700,
    "description": null,
    "is_active": true
}
```

**レスポンス**

- `201` 追加した

```json
{
    "data": {
        "id": 1,
        "booth_id": 1,
        "name": "から揚げ 5 個",
        "kind": "food",
        "price": 700,
        "description": null,
        "is_active": true
    }
}
```

- `401` 未ログイン / トークンが無効

```json
{
    "message": "ログインが必要です"
}
```

- `403` 役割が足りない / 担当ブースではない

```json
{
    "message": "この操作は許可されていません"
}
```

- `404` 対象が存在しない

```json
{
    "message": "見つかりません"
}
```

- `422` 入力不正

---

## PUT `/api/staff/booths/{booth}/menu-items/{menuItem}`

**メニューの変更（STAFF-04）**

- 実装: `App\Http\Controllers\StaffMenuItemController@update`
- 認証: 必要（Bearer トークン）
- 権限: staff, admin・担当ブースのみ（admin は全ブース）

送った項目だけを変える。販売をやめるときは `is_active: false`。メニューは URL のブースのものに限って探すので、他のブースのメニュー ID を指定すると 404。

**パスパラメータ**: `booth`、`menuItem`

**リクエストのバリデーション**（FormRequest から自動取得）

| フィールド | ルール |
|---|---|
| `name` | `sometimes` `required` `string` `max:100` |
| `kind` | `sometimes` `required` `string` `in:"food","merch"` |
| `price` | `sometimes` `required` `integer` `min:0` `max:1000000` |
| `description` | `sometimes` `nullable` `string` `max:500` |
| `is_active` | `sometimes` `boolean` |

**リクエスト例**

```json
{
    "price": 800,
    "is_active": false
}
```

**レスポンス**

- `200` 変更した

```json
{
    "data": {
        "is_active": false,
        "price": 800,
        "id": 1,
        "booth_id": 1,
        "name": "から揚げ 5 個",
        "kind": "food",
        "description": null
    }
}
```

- `401` 未ログイン / トークンが無効

```json
{
    "message": "ログインが必要です"
}
```

- `403` 役割が足りない / 担当ブースではない

```json
{
    "message": "この操作は許可されていません"
}
```

- `404` 対象が存在しない

```json
{
    "message": "見つかりません"
}
```

- `422` 入力不正

---

## DELETE `/api/staff/booths/{booth}/menu-items/{menuItem}`

**メニューの削除（STAFF-04）**

- 実装: `App\Http\Controllers\StaffMenuItemController@destroy`
- 認証: 必要（Bearer トークン）
- 権限: staff, admin・担当ブースのみ（admin は全ブース）

在庫の記録がまだ無いメニューだけ削除できる（削除すると在庫の履歴も消えるため）。記録があるときは 422 で、`is_active: false` にしてもらう。

**パスパラメータ**: `booth`、`menuItem`

**レスポンス**

- `200` 削除した

```json
{
    "message": "メニューを削除しました"
}
```

- `401` 未ログイン / トークンが無効

```json
{
    "message": "ログインが必要です"
}
```

- `403` 役割が足りない / 担当ブースではない

```json
{
    "message": "この操作は許可されていません"
}
```

- `404` 対象が存在しない

```json
{
    "message": "見つかりません"
}
```

- `422` 在庫の記録がある

```json
{
    "message": "在庫の記録があるメニューは削除できません。販売をやめるときは「販売中」をオフにしてください"
}
```


---

## POST `/api/staff/invitations/accept`

**招待コードを使う（STAFF-05）**

- 実装: `App\Http\Controllers\StaffInvitationController@accept`
- 認証: 必要（Bearer トークン）
- 権限: ログインユーザー全員（10 回/分/ユーザー）

ログインしていれば誰でも使える。コードは大文字・小文字、ハイフン、空白、全角を区別しない。使うとそのブースの担当になり、一般ユーザーはスタッフになる（admin は役割を変えない）。無効・期限切れ・使用済みはすべて同じ 422（どれに当たったかを推測させないため）。総当たり対策で 1 ユーザー 10 回/分。

**リクエストのバリデーション**（FormRequest から自動取得）

| フィールド | ルール |
|---|---|
| `code` | `required` `string` `max:32` |

**リクエスト例**

```json
{
    "code": "K7Q2-MX9P"
}
```

**レスポンス**

- `200` 担当になった

```json
{
    "data": {
        "user": {
            "id": 2,
            "name": "スタッフ サクラ",
            "email": "staff1@example.com",
            "role": "staff",
            "booths": [
                {
                    "booth_id": 1,
                    "name": "から揚げスタンド"
                }
            ]
        },
        "booth": {
            "booth_id": 1,
            "name": "から揚げスタンド"
        }
    }
}
```

- `401` 未ログイン / トークンが無効

```json
{
    "message": "ログインが必要です"
}
```

- `422` コードが無効・期限切れ・使用済み

```json
{
    "message": "招待コードが正しくないか、有効期限が切れています"
}
```

- `429` レート制限

```json
{
    "message": "リクエストが多すぎます。しばらくしてからもう一度お試しください"
}
```


---

## POST `/api/admin/booths/{booth}/invitations`

**招待コードの発行（ADMIN-05）**

- 実装: `App\Http\Controllers\AdminBoothStaffController@invite`
- 認証: 必要（Bearer トークン）
- 権限: admin

見間違えやすい 0 / O / 1 / I / L を除いた英数字 8 文字（4 文字ずつ区切って返す）。1 回限りで、有効期限は 7 日（`COURTQ_INVITATION_TTL_DAYS`）。

**パスパラメータ**: `booth`

**レスポンス**

- `201` 発行した

```json
{
    "data": {
        "code": "K7Q2-MX9P",
        "booth": {
            "booth_id": 1,
            "name": "から揚げスタンド"
        },
        "expires_at": "2026-10-23T19:30:00+09:00"
    }
}
```

- `401` 未ログイン / トークンが無効

```json
{
    "message": "ログインが必要です"
}
```

- `403` 役割が足りない / 担当ブースではない

```json
{
    "message": "この操作は許可されていません"
}
```

- `404` 対象が存在しない

```json
{
    "message": "見つかりません"
}
```


---

## GET `/api/admin/booths/{booth}/staff`

**ブースの担当スタッフ（ADMIN-06）**

- 実装: `App\Http\Controllers\AdminBoothStaffController@index`
- 認証: 必要（Bearer トークン）
- 権限: admin

**パスパラメータ**: `booth`

**レスポンス**

- `200` 担当スタッフ

```json
{
    "data": [
        {
            "user_id": 2,
            "name": "スタッフ サクラ",
            "email": "staff1@example.com",
            "role": "staff"
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

- `403` 役割が足りない / 担当ブースではない

```json
{
    "message": "この操作は許可されていません"
}
```

- `404` 対象が存在しない

```json
{
    "message": "見つかりません"
}
```


---

## DELETE `/api/admin/booths/{booth}/staff/{user}`

**担当の解除（ADMIN-06）**

- 実装: `App\Http\Controllers\AdminBoothStaffController@destroy`
- 認証: 必要（Bearer トークン）
- 権限: admin

担当ブースが 0 件になった staff は user に戻す（使わない権限を残さないため）。admin の役割は変えない。そのブースの担当でないユーザーを指定すると 404。

**パスパラメータ**: `booth`、`user`

**レスポンス**

- `200` 解除した

```json
{
    "message": "担当を解除しました"
}
```

- `401` 未ログイン / トークンが無効

```json
{
    "message": "ログインが必要です"
}
```

- `403` 役割が足りない / 担当ブースではない

```json
{
    "message": "この操作は許可されていません"
}
```

- `404` ブース・ユーザーが存在しない / 担当ではない

```json
{
    "message": "このブースの担当ではありません"
}
```


---

## リアルタイムイベント（Pusher Channels）

REST ではなく WebSocket で配信される通知。試合（ゲーム）ごとに 1 つの**公開チャンネル** `game.{gameId}` を購読する（内容が機微でなく、閲覧者ごとの認証の負荷を避けるため。**チャンネル認証 API（`/api/broadcasting/auth`）は無い**）。スタッフが混雑・在庫を更新すると（STAFF-02 / 03）、更新が確定してブース一覧のキャッシュを捨てたあとに、イベントを**キュー経由で** worker が送信する。

| チャンネル | イベント名 | ペイロード | 送られるとき |
|---|---|---|---|
| `game.{gameId}` | `booth.congestion.updated` | `{ "game_id": 1, "booth_id": 4, "game_booth_id": 14, "level": 3, "label": "混雑", "wait_minutes": 25, "updated_at": "2026-10-16T19:30:00+09:00" }` | 混雑の更新（STAFF-02） |
| `game.{gameId}` | `item.stock.updated` | `{ "game_id": 1, "booth_id": 2, "menu_item_id": 21, "status": "low", "label": "残りわずか", "quantity": 4, "updated_at": "2026-10-16T19:30:00+09:00" }` | 在庫の更新（STAFF-03） |

- ペイロードは小さく保つ（実測で約 140 バイト。Pusher の上限は約 10KB）。値は文字列・数値・null だけで、**更新したユーザーの情報は含めない**。
- 同じ値の更新でも送る（スタッフが「いま確認した」ことを最終更新として伝えるため）。受け取った側は `updated_at` を比べ、いま持っている値より古い更新は無視する。
- 受け取り方（Laravel Echo）: `echo.channel('game.1').listen('.booth.congestion.updated', handler)`（イベント名の先頭の `.` は、名前空間を付けずにそのまま使う指定）。
- 配信に失敗しても（Pusher の障害など）、スタッフの更新自体は成功する（失敗はログに記録される）。**WebSocket は取りこぼしうる**ので、画面は再接続したときにブース一覧・詳細を取り直し、つながっていない間は定期的に取り直す。
- Pusher のキー（`PUSHER_APP_KEY` など）が未設定のときは配信されず、画面はリアルタイムなしで動く（取り直しで更新される）。
- メニューの追加・変更・削除（STAFF-04）はイベントを送らない。来場者の画面は、次の取り直しで反映される。
