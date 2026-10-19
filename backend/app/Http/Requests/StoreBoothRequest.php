<?php

namespace App\Http\Requests;

use App\Enums\BoothCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** ブースの登録（POST）と更新（PUT。全項目を送り直す）。認可はルートの role:admin */
class StoreBoothRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'category' => ['required', 'string', Rule::enum(BoothCategory::class)],
            'location_label' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
