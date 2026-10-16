<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // ログイン: パスワードの総当たりを防ぐ。メール + IP の組み合わせごとに数える
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(10)->by($request->input('email').'|'.$request->ip());
        });

        // 登録: 大量のアカウント作成を防ぐ（会場の共有 Wi-Fi で同じ IP になることを考え、余裕を持たせる）
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });

        // 混雑・在庫の更新（STAFF-02 / 03）: 1 ユーザー 30 回/分。2 タップで更新する画面の連打や、トークンを使った大量書き込みを抑える
        RateLimiter::for('staff-write', function (Request $request) {
            return Limit::perMinute(30)->by('staff-write:'.$request->user()?->id);
        });

        // 招待コードの受け付け（STAFF-05）: 1 ユーザー 10 回/分。コードの総当たりを防ぐ
        RateLimiter::for('invitation-accept', function (Request $request) {
            return Limit::perMinute(10)->by('invitation-accept:'.$request->user()?->id);
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
