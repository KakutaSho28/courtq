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

$user = ['id' => 1, 'name' => 'アリス', 'email' => 'alice@example.com', 'role' => 'user', 'booths' => []];
$staffUser = ['id' => 2, 'name' => 'スタッフ サクラ', 'email' => 'staff1@example.com', 'role' => 'staff', 'booths' => [['booth_id' => 1, 'name' => 'から揚げスタンド']]];
$forbidden = ['description' => '役割が足りない / 担当ブースではない', 'example' => ['message' => 'この操作は許可されていません']];
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
        'description' => '`role` は `user` / `staff` / `admin`。`booths` は担当登録（`booth_staff`）されたブース。admin は全ブースを管理できるが、`booths` には担当登録したブースだけが入る。',
        'responses' => [200 => ['description' => 'ユーザー', 'example' => ['data' => $staffUser]], 401 => $unauthenticated],
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

    // ===== スタッフ =====
    'GET /api/staff/booths' => [
        'group' => 'スタッフ',
        'summary' => '管理できるブース（STAFF-01）',
        'description' => 'staff は担当ブースだけ、admin は全ブース。各ブースに、今日以降の出店予定を開始時刻の早い順で最大 3 件と、その試合の混雑の現在値を付ける（スタッフ画面から今日の試合の更新画面をすぐ開けるように）。',
        'responses' => [
            200 => ['description' => 'ブースの一覧', 'example' => ['data' => [[
                'booth_id' => 1, 'name' => 'から揚げスタンド', 'category' => 'food', 'location_label' => '2F コンコース東',
                'upcoming_games' => [[
                    'game_id' => 1, 'game_booth_id' => 10, 'home_team' => 'ベイサイド・ダイナモ', 'away_team' => 'シティ・ラインズ',
                    'tipoff_at' => '2026-10-16T19:05:00+09:00', 'status' => 'live', 'is_open' => true, 'congestion' => $congestion,
                ]],
            ]]]],
            401 => $unauthenticated,
            403 => $forbidden,
        ],
    ],
    'PUT /api/staff/games/{game}/booths/{booth}/congestion' => [
        'group' => 'スタッフ',
        'summary' => '混雑の更新（STAFF-02）',
        'description' => '担当ブースの混雑（4 段階）と待ち時間を更新する。現在値を書き換え、`congestion_logs` に 1 行追加し、ブース一覧のキャッシュを捨てる。一般ユーザー・他のブースのスタッフは 403（入力が不正でも 403 が先）。そのブースがこの試合に出店していなければ 404。',
        'request' => ['level' => 3, 'wait_minutes' => 25],
        'responses' => [
            200 => ['description' => '更新した', 'example' => ['data' => ['game_id' => 1, 'booth_id' => 1, 'game_booth_id' => 10, 'is_open' => true, 'congestion' => $congestion]]],
            401 => $unauthenticated,
            403 => $forbidden,
            404 => ['description' => '試合・ブースが存在しない / この試合に出店していない', 'example' => ['message' => 'この試合に出店していないブースです']],
            422 => ['description' => '入力不正（level は 1〜4、wait_minutes は 0〜180 または null）'],
            429 => $tooMany,
        ],
    ],
    'PUT /api/staff/games/{game}/menu-items/{menuItem}/stock' => [
        'group' => 'スタッフ',
        'summary' => '在庫の更新（STAFF-03）',
        'description' => '担当ブースのメニューの、この試合の在庫を作成または更新する（試合 × メニューで 1 行）。`stock_logs` に 1 行追加し、ブース一覧のキャッシュを捨てる。同じ値を送っても最終更新（`updated_at`）は新しくなる（「いま確認した」ことを伝えるため）。そのメニューのブースがこの試合に出店していなければ 404。',
        'request' => ['status' => 'low', 'quantity' => 4],
        'responses' => [
            200 => ['description' => '更新した', 'example' => ['data' => ['game_id' => 1, 'menu_item_id' => 1, 'stock' => ['status' => 'low', 'label' => '残りわずか', 'quantity' => 4, 'updated_at' => '2026-10-16T19:30:00+09:00', 'is_stale' => false]]]],
            401 => $unauthenticated,
            403 => $forbidden,
            404 => ['description' => '試合・メニューが存在しない / この試合に出店していない', 'example' => ['message' => 'この試合に出店していないブースのメニューです']],
            422 => ['description' => '入力不正（status は in_stock / low / sold_out、quantity は 0 以上の整数または null）'],
            429 => $tooMany,
        ],
    ],
    'GET /api/staff/booths/{booth}/menu-items' => [
        'group' => 'スタッフ',
        'summary' => 'メニューの一覧（STAFF-04）',
        'description' => '担当ブースのメニューを、販売終了（`is_active: false`）のものも含めて返す（来場者向けのブース詳細は販売中だけ。スタッフが販売を再開できるように）。`kind`、`id` の順。',
        'responses' => [
            200 => ['description' => 'メニューの一覧', 'example' => ['data' => [['id' => 1, 'booth_id' => 1, 'name' => 'から揚げ 5 個', 'kind' => 'food', 'price' => 700, 'description' => null, 'is_active' => true]]]],
            401 => $unauthenticated,
            403 => $forbidden,
            404 => $notFound,
        ],
    ],
    'POST /api/staff/booths/{booth}/menu-items' => [
        'group' => 'スタッフ',
        'summary' => 'メニューの追加（STAFF-04）',
        'description' => '担当ブースにメニューを追加する。`is_active` を省略すると販売中。',
        'request' => ['name' => 'から揚げ 5 個', 'kind' => 'food', 'price' => 700, 'description' => null, 'is_active' => true],
        'responses' => [
            201 => ['description' => '追加した', 'example' => ['data' => $menuItem = ['id' => 1, 'booth_id' => 1, 'name' => 'から揚げ 5 個', 'kind' => 'food', 'price' => 700, 'description' => null, 'is_active' => true]]],
            401 => $unauthenticated,
            403 => $forbidden,
            404 => $notFound,
            422 => ['description' => '入力不正'],
        ],
    ],
    'PUT /api/staff/booths/{booth}/menu-items/{menuItem}' => [
        'group' => 'スタッフ',
        'summary' => 'メニューの変更（STAFF-04）',
        'description' => '送った項目だけを変える。販売をやめるときは `is_active: false`。メニューは URL のブースのものに限って探すので、他のブースのメニュー ID を指定すると 404。',
        'request' => ['price' => 800, 'is_active' => false],
        'responses' => [
            200 => ['description' => '変更した', 'example' => ['data' => ['is_active' => false, 'price' => 800] + $menuItem]],
            401 => $unauthenticated,
            403 => $forbidden,
            404 => $notFound,
            422 => ['description' => '入力不正'],
        ],
    ],
    'DELETE /api/staff/booths/{booth}/menu-items/{menuItem}' => [
        'group' => 'スタッフ',
        'summary' => 'メニューの削除（STAFF-04）',
        'description' => '在庫の記録がまだ無いメニューだけ削除できる（削除すると在庫の履歴も消えるため）。記録があるときは 422 で、`is_active: false` にしてもらう。',
        'responses' => [
            200 => ['description' => '削除した', 'example' => ['message' => 'メニューを削除しました']],
            401 => $unauthenticated,
            403 => $forbidden,
            404 => $notFound,
            422 => ['description' => '在庫の記録がある', 'example' => ['message' => '在庫の記録があるメニューは削除できません。販売をやめるときは「販売中」をオフにしてください']],
        ],
    ],
    'POST /api/staff/invitations/accept' => [
        'group' => 'スタッフ',
        'summary' => '招待コードを使う（STAFF-05）',
        'description' => 'ログインしていれば誰でも使える。コードは大文字・小文字、ハイフン、空白、全角を区別しない。使うとそのブースの担当になり、一般ユーザーはスタッフになる（admin は役割を変えない）。無効・期限切れ・使用済みはすべて同じ 422（どれに当たったかを推測させないため）。総当たり対策で 1 ユーザー 10 回/分。',
        'request' => ['code' => 'K7Q2-MX9P'],
        'responses' => [
            200 => ['description' => '担当になった', 'example' => ['data' => ['user' => $staffUser, 'booth' => ['booth_id' => 1, 'name' => 'から揚げスタンド']]]],
            401 => $unauthenticated,
            422 => ['description' => 'コードが無効・期限切れ・使用済み', 'example' => ['message' => '招待コードが正しくないか、有効期限が切れています']],
            429 => $tooMany,
        ],
    ],

    // ===== 管理者 =====
    'POST /api/admin/booths/{booth}/invitations' => [
        'group' => '管理者',
        'summary' => '招待コードの発行（ADMIN-05）',
        'description' => '見間違えやすい 0 / O / 1 / I / L を除いた英数字 8 文字（4 文字ずつ区切って返す）。1 回限りで、有効期限は 7 日（`COURTQ_INVITATION_TTL_DAYS`）。',
        'responses' => [
            201 => ['description' => '発行した', 'example' => ['data' => ['code' => 'K7Q2-MX9P', 'booth' => ['booth_id' => 1, 'name' => 'から揚げスタンド'], 'expires_at' => '2026-10-23T19:30:00+09:00']]],
            401 => $unauthenticated,
            403 => $forbidden,
            404 => $notFound,
        ],
    ],
    'GET /api/admin/booths/{booth}/staff' => [
        'group' => '管理者',
        'summary' => 'ブースの担当スタッフ（ADMIN-06）',
        'responses' => [
            200 => ['description' => '担当スタッフ', 'example' => ['data' => [['user_id' => 2, 'name' => 'スタッフ サクラ', 'email' => 'staff1@example.com', 'role' => 'staff']]]],
            401 => $unauthenticated,
            403 => $forbidden,
            404 => $notFound,
        ],
    ],
    'DELETE /api/admin/booths/{booth}/staff/{user}' => [
        'group' => '管理者',
        'summary' => '担当の解除（ADMIN-06）',
        'description' => '担当ブースが 0 件になった staff は user に戻す（使わない権限を残さないため）。admin の役割は変えない。そのブースの担当でないユーザーを指定すると 404。',
        'responses' => [
            200 => ['description' => '解除した', 'example' => ['message' => '担当を解除しました']],
            401 => $unauthenticated,
            403 => $forbidden,
            404 => ['description' => 'ブース・ユーザーが存在しない / 担当ではない', 'example' => ['message' => 'このブースの担当ではありません']],
        ],
    ],
];
