<?php

namespace App\Http\Requests;

use App\Enums\GameStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** 試合の登録（POST）と更新（PUT。全項目を送り直す）。認可はルートの role:admin */
class StoreGameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'season' => ['required', 'string', 'max:20'],
            'section' => ['nullable', 'integer', 'min:1', 'max:999'],
            'home_team' => ['required', 'string', 'max:255'],
            'away_team' => ['required', 'string', 'max:255', 'different:home_team'],
            'venue_id' => ['required', 'integer', Rule::exists('venues', 'id')],
            'tipoff_at' => ['required', 'date'],
            'status' => ['sometimes', 'string', Rule::enum(GameStatus::class)],
        ];
    }
}
