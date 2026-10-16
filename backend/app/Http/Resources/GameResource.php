<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Game */
class GameResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'season' => $this->season,
            'section' => $this->section,
            'home_team' => $this->home_team,
            'away_team' => $this->away_team,
            'venue' => [
                'id' => $this->venue->id,
                'name' => $this->venue->name,
            ],
            'tipoff_at' => $this->tipoff_at->toIso8601String(),
            'status' => $this->status->value,
        ];
    }
}
