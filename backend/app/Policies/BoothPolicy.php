<?php

namespace App\Policies;

use App\Models\Booth;
use App\Models\User;

class BoothPolicy
{
    /** ブースの混雑・在庫・メニューを管理できるか: admin は全ブース、それ以外は担当（booth_staff）のブースだけ */
    public function manage(User $user, Booth $booth): bool
    {
        return $user->isAdmin() || $user->booths()->whereKey($booth->id)->exists();
    }
}
