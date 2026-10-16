<?php

/**
 * API ドキュメント（docs/api-endpoints.md）の説明とリクエスト/レスポンス例。
 *
 * ルート・パス・認証・バリデーションルールは `php artisan route:list --json` と FormRequest から自動生成されるので、
 * ここには「人が書く部分」だけを置く。ルートを追加/削除すると ApiDocsTest が失敗するので、
 * このファイルを更新して `php artisan docs:api` で docs/api-endpoints.md を再生成する。
 *
 * キーは「<HTTPメソッド> /<URI>」（HEAD を除く。複数メソッドは | で連結）。
 */

$user = ['id' => 1, 'name' => 'アリス', 'email' => 'alice@example.com'];
$auth = ['user' => $user, 'token' => '1|abcdef...（Sanctum のトークン）'];
$unauthenticated = ['description' => '未ログイン / トークンが無効', 'example' => ['message' => 'ログインが必要です']];
$notFound = ['description' => '対象が存在しない', 'example' => ['message' => '見つかりません']];
$freshness = ['meta' => ['stale_after_minutes' => 15]];
$game = [
    'id' => 1, 'season' => '2026-27', 'section' => 5, 'home_team' => 'ベイサイド・ダイナモ', 'away_team' => 'シティ・ラインズ',
    'venue' => ['id' => 1, 'name' => 'みなと第一アリーナ'], 'tipoff_at' => '2026-10-16T19:05:00+09:00', 'status' => 'live',
];
$congestion = ['level' => 3, 'label' => '混雑', 'wait_minutes' => 25, 'updated_at' => '2026-10-16T19:27:00+09:00', 'is_stale' => false];
$boothListItem = [
    'booth_id' => 1, 'game_booth_id' => 10, 'name' => 'から揚げスタンド', 'category' => 'food', 'location_label' => '2F コンコース東',
    'is_open' => true, 'congestion' => $congestion, 'has_stock' => true, 'is_favorite' => false,
];
$boothDetail = [
    'booth_id' => 1, 'game_booth_id' => 10, 'name' => 'から揚げスタンド', 'category' => 'food', 'location_label' => '2F コンコース東',
    'description' => '揚げたてのから揚げとドリンク', 'is_open' => true, 'congestion' => $congestion,
    'menu_items' => [[
        'id' => 1, 'name' => 'から揚げ 5 個', 'kind' => 'food', 'price' => 700, 'description' => null,
        'stock' => ['status' => 'low', 'label' => '残りわずか', 'quantity' => 4, 'updated_at' => '2026-10-16T19:10:00+09:00', 'is_stale' => true],
    ]],
    'is_favorite' => false,
];
$tooMany = ['description' => 'レート制限', 'example' => ['message' => 'リクエストが多すぎます。しばらくしてからもう一度お試しください']];

return [

    // ===== システム =====
    'GET /api/health' => [
        'group' => 'システム',
        'summary' => 'ヘルスチェック',
        'description' => 'DB に接続できるかを確認する。稼働監視と Railway の healthcheck が使う。DB に接続できないときは 503。',
        'responses' => [
            200 => ['description' => '正常', 'example' => ['data' => ['status' => 'ok', 'database' => 'ok']]],
            503 => ['description' => 'DB に接続できない', 'example' => ['data' => ['status' => 'error', 'database' => 'error']]],
        ],
    ],

    // ===== 認証 =====
    'POST /api/auth/register' => [
        'group' => '認証',
        'summary' => 'ユーザー登録',
        'description' => '登録と同時にトークンを発行する。以降は `Authorization: Bearer <token>` を付ける。登録されるユーザーの役割は常に一般ユーザー（`role` はクライアントから受け取らない）。',
        'request' => ['name' => 'アリス', 'email' => 'alice@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'],
        'responses' => [
            201 => ['description' => '登録成功', 'example' => ['data' => $auth]],
            422 => ['description' => '入力不正（メール重複など）', 'example' => ['message' => 'このメールアドレス はすでに使われています', 'errors' => ['email' => ['このメールアドレス はすでに使われています']]]],
            429 => $tooMany,
        ],
    ],
    'POST /api/auth/login' => [
        'group' => '認証',
        'summary' => 'ログイン',
        'description' => '既存のトークンはすべて失効し、新しいトークンを発行する（多重ログイン防止）。ログイン処理は `AuthService` に集約しており、外部 ID プロバイダ（SSO / OAuth）を追加するときもここを拡張する。同じメール + IP からは 1 分に 10 回まで。',
        'request' => ['email' => 'alice@example.com', 'password' => 'password123'],
        'responses' => [
            200 => ['description' => 'ログイン成功', 'example' => ['data' => $auth]],
            401 => ['description' => 'メールアドレスまたはパスワードが違う', 'example' => ['message' => 'メールアドレスまたはパスワードが正しくありません']],
            422 => ['description' => '入力不正'],
            429 => $tooMany,
        ],
    ],
    'POST /api/auth/logout' => [
        'group' => '認証',
        'summary' => 'ログアウト',
        'description' => '現在のトークンを失効させる。',
        'responses' => [200 => ['description' => 'ログアウト成功', 'example' => ['message' => 'ログアウトしました']], 401 => $unauthenticated],
    ],
    'GET /api/me' => [
        'group' => '認証',
        'summary' => 'ログイン中のユーザー',
        'description' => '役割（`role`）と担当ブースは WP-B4 で追加する。',
        'responses' => [200 => ['description' => 'ユーザー', 'example' => ['data' => $user]], 401 => $unauthenticated],
    ],

    // ===== 試合 =====
    'GET /api/games' => [
        'group' => '試合',
        'summary' => '試合の一覧（GAME-01）',
        'description' => '今日開催の試合が先頭で、あとは開始時刻の早い順（最大 100 件）。`date` を指定するとその日の試合だけ、`from` を指定するとその日以降の試合だけ。どちらも省略すると今日以降の試合。日付はアプリのタイムゾーン（日本時間）で扱う。',
        'query' => ['date' => '`2026-10-16` の形式。その日の試合だけ', 'from' => '`2026-10-01` の形式。その日以降の試合だけ（`date` があれば無視）'],
        'responses' => [200 => ['description' => '試合の一覧', 'example' => ['data' => [$game]]], 401 => $unauthenticated, 422 => ['description' => '日付の形式が不正（Y-m-d）']],
    ],
    'GET /api/games/{game}' => [
        'group' => '試合',
        'summary' => '試合の詳細（GAME-02）',
        'responses' => [200 => ['description' => '試合', 'example' => ['data' => $game]], 401 => $unauthenticated, 404 => $notFound],
    ],

    // ===== ブース =====
    'GET /api/games/{game}/booths' => [
        'group' => 'ブース',
        'summary' => '試合のブース一覧（BOOTH-01）',
        'description' => <<<'TXT'
混雑（4 段階 + 待ち時間）と最終更新を含む軽い一覧。メニューは含まない（詳細で取る）。全員に共通の部分はサーバーで 20 秒（`COURTQ_LIST_CACHE_SECONDS`）キャッシュされ、混雑・在庫を更新すると破棄される。お気に入りの印と絞り込みはユーザーごとにキャッシュの外で付ける。

- `category`: `food` / `merch` は兼営（`both`）のブースも含む。`both` は兼営のブースだけ
- `sort`: `name`（既定）/ `congestion`（空いている順）/ `wait`（待ち時間の短い順）。どの並び方でも、営業中が先で閉店中は最後。混雑・待ち時間が未入力のブースは入力済みより後ろ。名前順は文字コード順（五十音順ではない）
- `in_stock=1`: 販売中のメニューに、売り切れでない在庫が 1 つ以上あるブースだけ（`has_stock` が true）
- `favorites=1`: 自分のお気に入りだけ
- `congestion.is_stale`: 最終更新から `meta.stale_after_minutes`（15 分）を超えたら true（「情報が古い可能性があります」を出す目印）。一度も更新されていないときは `level` などが null で、`is_stale` は false
TXT,
        'query' => ['category' => '`food` / `merch` / `both`', 'sort' => '`name`（既定）/ `congestion` / `wait`', 'in_stock' => '`1` で在庫のあるブースだけ', 'favorites' => '`1` で自分のお気に入りだけ'],
        'responses' => [200 => ['description' => 'ブースの一覧', 'example' => ['data' => [$boothListItem]] + $freshness], 401 => $unauthenticated, 404 => $notFound, 422 => ['description' => '絞り込み・並び順の指定が不正']],
    ],
    'GET /api/games/{game}/booths/{booth}' => [
        'group' => 'ブース',
        'summary' => 'ブースの詳細（BOOTH-02）',
        'description' => '混雑と、販売中のメニュー（`kind` が `food` / `merch`。画面で飲食・物販に分ける）、その試合の在庫と最終更新。在庫がまだ登録されていないメニューは `stock` が null。そのブースがこの試合に出店していないときは 404。',
        'responses' => [
            200 => ['description' => 'ブース', 'example' => ['data' => $boothDetail] + $freshness],
            401 => $unauthenticated,
            404 => ['description' => '試合またはブースが存在しない / この試合に出店していない', 'example' => ['message' => 'この試合に出店していないブースです']],
        ],
    ],
    'GET /api/games/{game}/booths/{booth}/congestion-history' => [
        'group' => 'ブース',
        'summary' => '混雑の履歴（BOOTH-03）',
        'description' => '混雑の推移のグラフ用。古い順で、直近の最大 200 件。更新したユーザーは返さない。',
        'responses' => [
            200 => ['description' => '履歴', 'example' => ['data' => [['level' => 3, 'label' => '混雑', 'wait_minutes' => 25, 'recorded_at' => '2026-10-16T19:27:00+09:00']]]],
            401 => $unauthenticated,
            404 => $notFound,
        ],
    ],

    // ===== お気に入り =====
    'GET /api/me/favorites' => [
        'group' => 'お気に入り',
        'summary' => 'お気に入りのブース（FAV-01）',
        'description' => '自分のお気に入りだけを名前順で返す。試合ごとの混雑は、ブース一覧の `favorites=1` で取る。',
        'responses' => [200 => ['description' => 'お気に入り', 'example' => ['data' => [['booth_id' => 1, 'name' => 'から揚げスタンド', 'category' => 'food', 'location_label' => '2F コンコース東']]]], 401 => $unauthenticated],
    ],
    'PUT /api/booths/{booth}/favorite' => [
        'group' => 'お気に入り',
        'summary' => 'お気に入りに追加（FAV-02）',
        'description' => '追加済みでも成功する（冪等）。どの役割でも使える。',
        'responses' => [200 => ['description' => '追加した', 'example' => ['data' => ['booth_id' => 1, 'is_favorite' => true]]], 401 => $unauthenticated, 404 => $notFound],
    ],
    'DELETE /api/booths/{booth}/favorite' => [
        'group' => 'お気に入り',
        'summary' => 'お気に入りから外す（FAV-03）',
        'description' => '外れていても成功する（冪等）。',
        'responses' => [200 => ['description' => '外した', 'example' => ['data' => ['booth_id' => 1, 'is_favorite' => false]]], 401 => $unauthenticated, 404 => $notFound],
    ],
];
