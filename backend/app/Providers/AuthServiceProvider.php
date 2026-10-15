<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /** モデルとポリシーの対応。WP-B4 で BoothPolicy を登録する */
    protected $policies = [];

    public function boot(): void
    {
        //
    }
}
