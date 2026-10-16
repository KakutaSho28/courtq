<?php

namespace App\Policies;

use App\Models\MenuItem;
use App\Models\User;

class MenuItemPolicy
{
    public function __construct(private readonly BoothPolicy $booths) {}

    /** メニュー（とその在庫）を管理できるか: そのメニューのブースを管理できる人だけ */
    public function manage(User $user, MenuItem $menuItem): bool
    {
        return $this->booths->manage($user, $menuItem->booth);
    }
}
