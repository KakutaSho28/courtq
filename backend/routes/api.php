<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BoothController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\HealthController;
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
});
