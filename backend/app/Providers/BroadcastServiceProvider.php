<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // 配信するのは公開チャンネル `game.{gameId}` だけ（混雑・在庫は機微情報ではなく、閲覧者ごとの認証負荷も避けたい）。
        // そのためチャンネル認証用のルート（/api/broadcasting/auth）は登録しない。
    }
}
