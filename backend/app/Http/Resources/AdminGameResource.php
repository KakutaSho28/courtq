<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * 管理画面の試合（GameResource に、会場の詳細と割り当て済みブースの数を足したもの）
 *
 * @mixin \App\Models\Game
 */
class AdminGameResource extends GameResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'booths_count' => $this->game_booths_count ?? $this->gameBooths()->count(),
        ];
    }
}
