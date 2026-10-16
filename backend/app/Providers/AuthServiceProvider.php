<?php

namespace App\Providers;

use App\Models\Booth;
use App\Models\MenuItem;
use App\Policies\BoothPolicy;
use App\Policies\MenuItemPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /** モデルとポリシーの対応 */
    protected $policies = [
        Booth::class => BoothPolicy::class,
        MenuItem::class => MenuItemPolicy::class,
    ];

    public function boot(): void
    {
        //
    }
}
