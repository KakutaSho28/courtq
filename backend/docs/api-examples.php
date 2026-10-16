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
];
