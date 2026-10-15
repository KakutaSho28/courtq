<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * API 専用アプリのため、未認証でもリダイレクトしない（null なら AuthenticationException → Handler が 401 JSON を返す）。
     * ログイン画面のルートは存在しないので、Accept ヘッダーの有無で 500 にならないようにする。
     */
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}
