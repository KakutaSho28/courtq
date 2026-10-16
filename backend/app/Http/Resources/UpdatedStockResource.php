<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 在庫の更新結果（STAFF-03）。どの試合のどのメニューかを付けて返す
 *
 * @mixin \App\Models\ItemStock
 */
class UpdatedStockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'game_id' => $this->game_id,
            'menu_item_id' => $this->menu_item_id,
            'stock' => new StockResource($this->resource),
        ];
    }
}
