<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ログインユーザー（/me、ログイン・登録の応答）。
 * booths は booth_staff に登録された担当ブース。admin は全ブースを管理できるが、ここには担当登録したブースだけが入る。
 *
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'booths' => $this->booths()->orderBy('booths.name')->get(['booths.id', 'booths.name'])
                ->map(fn ($booth) => ['booth_id' => $booth->id, 'name' => $booth->name])
                ->all(),
        ];
    }
}
