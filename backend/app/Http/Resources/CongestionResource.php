<?php

namespace App\Http\Resources;

use App\Support\Freshness;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 混雑の現在値（試合ごとのブース = game_booths の 1 行）。詳細・スタッフ画面・更新の応答で同じ形にする。
 *
 * @mixin \App\Models\GameBooth
 */
class CongestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'level' => $this->congestion_level?->value,
            'label' => $this->congestion_level?->label(),
            'wait_minutes' => $this->wait_minutes,
            'updated_at' => $this->congestion_updated_at?->toIso8601String(),
            'is_stale' => Freshness::isStale($this->congestion_updated_at),
        ];
    }
}
