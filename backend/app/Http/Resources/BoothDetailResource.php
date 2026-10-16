<?php

namespace App\Http\Resources;

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

        return [
            'booth_id' => $booth->id,
            'game_booth_id' => $gameBooth->id,
            'name' => $booth->name,
            'category' => $booth->category->value,
            'location_label' => $booth->location_label,
            'description' => $booth->description,
            'is_open' => $gameBooth->is_open,
            'congestion' => new CongestionResource($gameBooth),
            'menu_items' => $this->resource['items']->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'kind' => $item->kind->value,
                'price' => $item->price,
                'description' => $item->description,
                'stock' => $item->stock === null ? null : new StockResource($item->stock),
            ])->all(),
            'is_favorite' => $this->resource['is_favorite'],
        ];
    }
}
