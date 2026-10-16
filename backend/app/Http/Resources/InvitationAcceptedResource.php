<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 招待コードを使った結果（STAFF-05）。リソースには ['user' => User, 'booth' => Booth] を渡す。
 * 役割と担当ブースが変わるので、画面が /me を取り直さなくてよいよう新しいユーザー情報も返す
 */
class InvitationAcceptedResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user' => new UserResource($this->resource['user']),
            'booth' => ['booth_id' => $this->resource['booth']->id, 'name' => $this->resource['booth']->name],
        ];
    }
}
