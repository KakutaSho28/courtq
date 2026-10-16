<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 混雑の履歴の 1 点（BOOTH-03）。更新したユーザーは返さない（来場者に見せる情報ではないため）。
 *
 * @mixin \App\Models\CongestionLog
 */
class CongestionHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'level' => $this->level->value,
            'label' => $this->level->label(),
            'wait_minutes' => $this->wait_minutes,
            'recorded_at' => $this->created_at->toIso8601String(),
        ];
    }
}
