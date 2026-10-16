<?php

namespace App\Http\Requests;

use App\Enums\CongestionLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCongestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // 認可はルートの can:manage,booth（Policy）で済ませている
    }

    public function rules(): array
    {
        return [
            'level' => ['required', 'integer', Rule::enum(CongestionLevel::class)],
            'wait_minutes' => ['nullable', 'integer', 'min:0', 'max:'.config('courtq.wait_minutes_max')],
        ];
    }
}
