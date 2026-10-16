<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API の JSON を、日本語を \uXXXX にエスケープせずに返す。
 * エスケープすると日本語 1 文字が 3 バイトから 6 バイトになり、会場の弱い回線で読む一覧の応答が約 2 倍に膨らむため。
 */
class UnescapedJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $response->setEncodingOptions($response->getEncodingOptions() | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $response;
    }
}
