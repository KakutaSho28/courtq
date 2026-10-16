<?php

use App\Http\Controllers\AdminBoothStaffController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BoothController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\StaffBoothController;
use App\Http\Controllers\StaffInvitationController;
use App\Http\Controllers\StaffMenuItemController;
use App\Http\Controllers\StaffStatusController;
use Illuminate\Support\Facades\Route;

// ===== 認証不要 =====

// ヘルスチェック（DB 接続確認。稼働監視・Railway healthcheck 用）
Route::get('/health', HealthController::class);
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

// ===== 認証必須 =====
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // 試合（GAME-01〜02）
    Route::get('/games', [GameController::class, 'index']);
    Route::get('/games/{game}', [GameController::class, 'show']);

    // ブース（BOOTH-01〜03）
    Route::get('/games/{game}/booths', [BoothController::class, 'index']);
    Route::get('/games/{game}/booths/{booth}', [BoothController::class, 'show']);
    Route::get('/games/{game}/booths/{booth}/congestion-history', [BoothController::class, 'congestionHistory']);

    // お気に入り（FAV-01〜03）
    Route::get('/me/favorites', [FavoriteController::class, 'index']);
    Route::put('/booths/{booth}/favorite', [FavoriteController::class, 'store']);
    Route::delete('/booths/{booth}/favorite', [FavoriteController::class, 'destroy']);

    // 招待コードを使う（STAFF-05）。ログインしていれば誰でも。総当たり対策で 1 ユーザー 10 回/分
    Route::post('/staff/invitations/accept', [StaffInvitationController::class, 'accept'])->middleware('throttle:invitation-accept');

    // ===== スタッフ・管理者（書き込み API の守り方は docs/progress.md「WP-B4 の認可ルール」） =====
    // 検査の順番: auth:sanctum → role（EnsureRole）→ throttle → ID の解決 → can（Policy）→ FormRequest
    Route::prefix('staff')->middleware('role:staff,admin')->group(function () {
        // STAFF-01 管理できるブース
        Route::get('/booths', [StaffBoothController::class, 'index']);

        // STAFF-02 / 03 混雑・在庫の更新（担当ブースだけ。1 ユーザー 30 回/分）
        Route::put('/games/{game}/booths/{booth}/congestion', [StaffStatusController::class, 'updateCongestion'])
            ->middleware(['can:manage,booth', 'throttle:staff-write']);
        Route::put('/games/{game}/menu-items/{menuItem}/stock', [StaffStatusController::class, 'updateStock'])
            ->middleware(['can:manage,menuItem', 'throttle:staff-write']);

        // STAFF-04 メニューの管理（担当ブースだけ。メニューは URL のブースのものに限って解決する）
        Route::middleware('can:manage,booth')->scopeBindings()->group(function () {
            Route::get('/booths/{booth}/menu-items', [StaffMenuItemController::class, 'index']);
            Route::post('/booths/{booth}/menu-items', [StaffMenuItemController::class, 'store']);
            Route::put('/booths/{booth}/menu-items/{menuItem}', [StaffMenuItemController::class, 'update']);
            Route::delete('/booths/{booth}/menu-items/{menuItem}', [StaffMenuItemController::class, 'destroy']);
        });
    });

    Route::prefix('admin')->middleware('role:admin')->group(function () {
        // ADMIN-05 招待コードの発行 / ADMIN-06 担当スタッフの一覧・解除
        Route::post('/booths/{booth}/invitations', [AdminBoothStaffController::class, 'invite']);
        Route::get('/booths/{booth}/staff', [AdminBoothStaffController::class, 'index']);
        Route::delete('/booths/{booth}/staff/{user}', [AdminBoothStaffController::class, 'destroy']);
    });
});
