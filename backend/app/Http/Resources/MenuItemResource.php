<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * メニュー（STAFF-04 の応答）
 *
 * @mixin \App\Models\MenuItem
 */
class MenuItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booth_id' => $this->booth_id,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'price' => $this->price,
            'description' => $this->description,
            'is_active' => $this->is_active,
        ];
    }
}
