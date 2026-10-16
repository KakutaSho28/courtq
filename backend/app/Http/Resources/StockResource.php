<?php

namespace App\Http\Resources;

use App\Support\Freshness;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 在庫の現在値（item_stocks の 1 行）。ブース詳細の各メニューに入る形
 *
 * @mixin \App\Models\ItemStock
 */
class StockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status->value,
            'label' => $this->status->label(),
            'quantity' => $this->quantity,
            'updated_at' => $this->updated_at->toIso8601String(),
            'is_stale' => Freshness::isStale($this->updated_at),
        ];
    }
}
