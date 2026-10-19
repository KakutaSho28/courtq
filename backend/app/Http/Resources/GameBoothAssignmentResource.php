<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 試合へのブースの割り当て（ADMIN-04）。全ブースについて、割り当て済みか・営業中か・外せないか
 *
 * @property array{booth: \App\Models\Booth, assigned: bool, is_open: bool, has_records: bool} $resource
 */
class GameBoothAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'booth_id' => $this->resource['booth']->id,
            'name' => $this->resource['booth']->name,
            'category' => $this->resource['booth']->category->value,
            'location_label' => $this->resource['booth']->location_label,
            'assigned' => $this->resource['assigned'],
            'is_open' => $this->resource['is_open'],
            'has_records' => $this->resource['has_records'],
        ];
    }
}
