<?php

namespace App\Services;

use App\Models\Booth;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class FavoriteService
{
    /**
     * お気に入りのブース（FAV-01）。
     *
     * @return Collection<int, Booth>
     */
    public function list(User $user): Collection
    {
        return $user->favoriteBooths()->orderBy('booths.name')->get();
    }

    /** お気に入りに追加する（FAV-02）。すでに追加済みでも何も変わらない（冪等） */
    public function add(User $user, Booth $booth): void
    {
        $user->favoriteBooths()->syncWithoutDetaching([$booth->id]);
    }

    /** お気に入りから外す（FAV-03）。追加していなくても何も変わらない（冪等） */
    public function remove(User $user, Booth $booth): void
    {
        $user->favoriteBooths()->detach($booth->id);
    }
}
