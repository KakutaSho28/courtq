<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // カンマ区切り。本番は Vercel のドメインのみを指定する（例: https://courtq.vercel.app）
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost,http://localhost:5173')),
    ))),

    // Vercel のプレビュー URL 等を許可する正規表現（カンマ区切り、任意）。例: #^https://courtq-.*\.vercel\.app$#
    'allowed_origins_patterns' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGINS_PATTERNS', '')),
    ))),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    // プリフライト（OPTIONS）の結果をブラウザに 1 時間キャッシュさせる。0 だと、Vercel から Railway への認証付きの
    // 全リクエストの前に確認の通信が毎回入り、会場の弱い回線で往復が倍になる
    'max_age' => 3600,

    'supports_credentials' => false,

];
