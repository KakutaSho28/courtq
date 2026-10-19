<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVenueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // 認可はルートの role:admin
    }

    public function rules(): array
    {
        return [
            // 同じ名前の会場を重ねない（CSV 取り込みは会場名で照合するため）
            'name' => ['required', 'string', 'max:255', Rule::unique('venues', 'name')],
            'address' => ['nullable', 'string', 'max:255'],
            'map_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
