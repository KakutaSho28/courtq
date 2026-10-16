<?php

namespace App\Http\Resources;

use App\Services\StaffInvitationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 発行した招待コード（ADMIN-05）。コードは読みやすいよう 4 文字ずつ区切って返す
 *
 * @mixin \App\Models\StaffInvitation
 */
class InvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => StaffInvitationService::format($this->code),
            'booth' => ['booth_id' => $this->booth->id, 'name' => $this->booth->name],
            'expires_at' => $this->expires_at->toIso8601String(),
        ];
    }
}
