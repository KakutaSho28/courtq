<?php

namespace App\Http\Resources;

use App\Enums\CongestionLevel;
use App\Support\Freshness;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * ブース一覧の 1 件（BOOTH-01）。リソースには BoothService::listForGame() の配列の 1 要素を渡す。
 * 混雑の is_stale は時間とともに変わるので、キャッシュには入れずここで毎回計算する。
 */
class BoothListItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $item = $this->resource;
        $updatedAt = $item['congestion_updated_at'] !== null ? Carbon::parse($item['congestion_updated_at']) : null;
        $level = $item['congestion_level'] !== null ? CongestionLevel::from($item['congestion_level']) : null;

        return [
            'booth_id' => $item['booth_id'],
            'game_booth_id' => $item['game_booth_id'],
            'name' => $item['name'],
            'category' => $item['category'],
            'location_label' => $item['location_label'],
            'is_open' => $item['is_open'],
            'congestion' => [
                'level' => $level?->value,
                'label' => $level?->label(),
                'wait_minutes' => $item['wait_minutes'],
                'updated_at' => $updatedAt?->toIso8601String(),
                'is_stale' => Freshness::isStale($updatedAt),
            ],
            'has_stock' => $item['has_stock'],
            'is_favorite' => $item['is_favorite'],
        ];
    }
}
