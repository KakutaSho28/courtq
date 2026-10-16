<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * API のエラーは常に { "message": "..." } で返す（422 のみ errors を追加）。メッセージは日本語。
 */
class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // Service 層の業務ルール違反を { "message": ... } で返す
        $this->renderable(function (ServiceException $e) {
            return $this->apiJson(['message' => $e->getMessage()], $e->status);
        });

        // 401: 未ログイン / トークン無効
        $this->renderable(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return $this->apiJson(['message' => 'ログインが必要です'], 401);
            }
        });

        // 403: Policy・ロールの拒否（AuthorizationException → AccessDeniedHttpException）を API 共通の形式で返す
        $this->renderable(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return $this->apiJson(['message' => 'この操作は許可されていません'], 403);
            }
        });

        // 404: 存在しないルート / モデル
        $this->renderable(function (NotFoundHttpException|ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return $this->apiJson(['message' => '見つかりません'], 404);
            }
        });

        // 422: 入力不正。message は最初のエラー文、詳細は errors
        $this->renderable(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return $this->apiJson([
                    'message' => $e->validator->errors()->first() ?: '入力内容を確認してください',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        // 429: レート制限
        $this->renderable(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*')) {
                return $this->apiJson(['message' => 'リクエストが多すぎます。しばらくしてからもう一度お試しください'], 429, $e->getHeaders());
            }
        });
    }

    /**
     * API のエラー応答。日本語を \uXXXX にエスケープしない（UnescapedJsonResponse と同じ理由）。
     * 存在しない URL の 404 は、api ミドルウェアグループに入る前に応答が作られるため、ミドルウェアでは直せない。
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string|array<int, string>>  $headers
     */
    private function apiJson(array $data, int $status, array $headers = []): JsonResponse
    {
        return response()->json($data, $status, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
