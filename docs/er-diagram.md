# データモデル（ER 図）

テーブルやカラムを変えたら、この図も更新する（ルールは [CLAUDE.md](../CLAUDE.md)）。マイグレーションは `backend/database/migrations/`。

```mermaid
erDiagram
    users ||--o{ booth_staff : "担当"
    booths ||--o{ booth_staff : "担当スタッフ"
    users ||--o{ favorites : "お気に入り"
    booths ||--o{ favorites : ""
    venues ||--o{ games : "開催"
    games ||--o{ game_booths : "出店"
    booths ||--o{ game_booths : ""
    booths ||--o{ menu_items : "メニュー"
    games ||--o{ item_stocks : ""
    menu_items ||--o{ item_stocks : "試合ごとの在庫"
    booths ||--o{ staff_invitations : "招待コード"
    game_booths ||--o{ congestion_logs : "履歴"
    item_stocks ||--o{ stock_logs : "履歴"
    users ||--o{ congestion_logs : "更新者"
    users ||--o{ stock_logs : "更新者"

    users {
        bigint id PK
        string name
        string email UK
        string password
        enum role "user / staff / admin（既定 user）"
        string provider "NULL 可。外部 ID プロバイダ用"
        string provider_user_id "NULL 可。(provider, provider_user_id) で一意"
    }
    venues {
        bigint id PK
        string name
        string address "NULL 可"
        text map_note "NULL 可"
    }
    games {
        bigint id PK
        string season
        smallint section "NULL 可（節）"
        string home_team
        string away_team
        bigint venue_id FK
        datetime tipoff_at
        enum status "scheduled / live / finished"
    }
    booths {
        bigint id PK
        string name
        enum category "food / merch / both"
        string location_label
        text description "NULL 可"
    }
    game_booths {
        bigint id PK
        bigint game_id FK
        bigint booth_id FK
        bool is_open
        tinyint congestion_level "NULL 可。1〜4"
        smallint wait_minutes "NULL 可。0〜180"
        timestamp congestion_updated_at "NULL 可"
        bigint congestion_updated_by FK "NULL 可"
    }
    menu_items {
        bigint id PK
        bigint booth_id FK
        string name
        enum kind "food / merch"
        int price "円"
        text description "NULL 可"
        bool is_active
    }
    item_stocks {
        bigint id PK
        bigint game_id FK
        bigint menu_item_id FK
        enum status "in_stock / low / sold_out"
        int quantity "NULL 可"
        bigint updated_by FK "NULL 可"
        timestamp updated_at "最終更新"
    }
    booth_staff {
        bigint id PK
        bigint booth_id FK
        bigint user_id FK
    }
    staff_invitations {
        bigint id PK
        bigint booth_id FK
        string code UK "ランダム・1 回限り"
        timestamp expires_at
        timestamp used_at "NULL 可"
        bigint used_by FK "NULL 可"
        bigint created_by FK "NULL 可"
    }
    congestion_logs {
        bigint id PK
        bigint game_booth_id FK
        tinyint level
        smallint wait_minutes "NULL 可"
        bigint user_id FK "NULL 可"
        timestamp created_at
    }
    stock_logs {
        bigint id PK
        bigint item_stock_id FK
        enum status
        int quantity "NULL 可"
        bigint user_id FK "NULL 可"
        timestamp created_at
    }
    favorites {
        bigint id PK
        bigint user_id FK
        bigint booth_id FK
    }
```

## 一意制約

| テーブル | 一意 |
|---|---|
| `game_booths` | (`game_id`, `booth_id`) |
| `item_stocks` | (`game_id`, `menu_item_id`) |
| `booth_staff` | (`booth_id`, `user_id`) |
| `favorites` | (`user_id`, `booth_id`) |
| `staff_invitations` | `code` |
| `games` | (`season`, `section`, `home_team`, `away_team`)。CSV の再インポートで同じ試合を重複登録しないため |
| `users` | `email`、(`provider`, `provider_user_id`) |

> 注意: MySQL の一意制約は NULL を「互いに異なる値」として扱うため、`section` が NULL の試合は上の制約では重複を防げない。CSV 取り込み（WP-B7）ではアプリ側でも重複を確認する。

## 削除時の扱い

- 試合を消すと、その試合の `game_booths`・`item_stocks`（とその履歴）も消える。ブース・メニューは残る。会場は試合があると消せない。
- ユーザーを消しても、履歴（`congestion_logs` / `stock_logs`）と更新者の記録は残り、ユーザーの参照だけが NULL になる。
- 履歴は追記専用（`created_at` のみ）。

## 時刻

`games.tipoff_at` など日時は、アプリのタイムゾーン（`APP_TIMEZONE`、既定 `Asia/Tokyo`）の時刻で保存する。API は ISO 8601（`+09:00` 付き）で返す。
