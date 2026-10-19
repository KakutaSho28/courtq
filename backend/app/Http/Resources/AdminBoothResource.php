<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Booth */
class AdminBoothResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'booth_id' => $this->id,
            'name' => $this->name,
            'category' => $this->category->value,
            'location_label' => $this->location_label,
            'description' => $this->description,
            'staff_count' => $this->staff_count ?? $this->staff()->count(),
            'games_count' => $this->game_booths_count ?? $this->gameBooths()->count(),
        ];
    }
}
