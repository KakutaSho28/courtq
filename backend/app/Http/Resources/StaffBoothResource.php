<?php

namespace App\Http\Resources;

use App\Models\GameBooth;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * スタッフが管理できるブース（STAFF-01）。今日以降の出店予定（最大 3 件）と、その試合の混雑の現在値を付ける
 *
 * @mixin \App\Models\Booth
 */
class StaffBoothResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'booth_id' => $this->id,
            'name' => $this->name,
            'category' => $this->category->value,
            'location_label' => $this->location_label,
            'upcoming_games' => $this->upcomingGameBooths->map(fn (GameBooth $gb) => [
                'game_id' => $gb->game_id,
                'game_booth_id' => $gb->id,
                'home_team' => $gb->game->home_team,
                'away_team' => $gb->game->away_team,
                'tipoff_at' => $gb->game->tipoff_at->toIso8601String(),
                'status' => $gb->game->status->value,
                'is_open' => $gb->is_open,
                'congestion' => new CongestionResource($gb),
            ])->all(),
        ];
    }
}
