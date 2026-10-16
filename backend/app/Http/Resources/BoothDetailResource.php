<?php

namespace App\Http\Resources;

use App\Support\Freshness;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ブース詳細（BOOTH-02）。リソースには BoothService::detail() の返り値（game_booth / items / is_favorite）を渡す。
 */
class BoothDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $gameBooth = $this->resource['game_booth'];
        $booth = $gameBooth->booth;
        $level = $gameBooth->congestion_level;

        return [
            'booth_id' => $booth->id,
            'game_booth_id' => $gameBooth->id,
            'name' => $booth->name,
            'category' => $booth->category->value,
            'location_label' => $booth->location_label,
            'description' => $booth->description,
            'is_open' => $gameBooth->is_open,
            'congestion' => [
                'level' => $level?->value,
                'label' => $level?->label(),
                'wait_minutes' => $gameBooth->wait_minutes,
                'updated_at' => $gameBooth->congestion_updated_at?->toIso8601String(),
                'is_stale' => Freshness::isStale($gameBooth->congestion_updated_at),
            ],
            'menu_items' => $this->resource['items']->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'kind' => $item->kind->value,
                'price' => $item->price,
                'description' => $item->description,
                'stock' => $item->stock === null ? null : [
                    'status' => $item->stock->status->value,
                    'label' => $item->stock->status->label(),
                    'quantity' => $item->stock->quantity,
                    'updated_at' => $item->stock->updated_at->toIso8601String(),
                    'is_stale' => Freshness::isStale($item->stock->updated_at),
                ],
            ])->all(),
            'is_favorite' => $this->resource['is_favorite'],
        ];
    }
}
