<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 混雑の更新結果（STAFF-02）
 *
 * @mixin \App\Models\GameBooth
 */
class GameBoothStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'game_id' => $this->game_id,
            'booth_id' => $this->booth_id,
            'game_booth_id' => $this->id,
            'is_open' => $this->is_open,
            'congestion' => new CongestionResource($this->resource),
        ];
    }
}
