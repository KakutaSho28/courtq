<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\AuthTokenResource;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /** AUTH-01 ユーザー登録 */
    public function register(RegisterRequest $request): JsonResponse
    {
        return (new AuthTokenResource($this->auth->register($request->validated())))
            ->response()
            ->setStatusCode(201);
    }

    /** AUTH-02 ログイン */
    public function login(LoginRequest $request): AuthTokenResource
    {
        return new AuthTokenResource($this->auth->login($request->email, $request->password));
    }

    /** AUTH-03 ログアウト */
    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user());

        return response()->json(['message' => 'ログアウトしました']);
    }

    /** AUTH-04 ログイン中のユーザー */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
