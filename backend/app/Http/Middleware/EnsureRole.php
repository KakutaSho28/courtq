<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 役割の確認。使い方: ->middleware('role:staff,admin') / ->middleware('role:admin')
 *
 * 役割は DB の users.role だけを見る（リクエストの値は使わない）。足りなければ 403（Handler が共通の JSON にする）。
 * ID の存在確認（ルートモデルバインディング）より前に評価されるよう、App\Http\Kernel でミドルウェアの優先順位に登録している。
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        if ($user === null) {
            throw new AuthenticationException;
        }

        if (! in_array($user->role->value, $roles, true)) {
            throw new AccessDeniedHttpException;
        }

        return $next($request);
    }
}
