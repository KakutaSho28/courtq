<?php

namespace App\Http\Requests;

use App\Enums\StockStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // 認可はルートの can:manage,menuItem（Policy）で済ませている
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::enum(StockStatus::class)],
            'quantity' => ['nullable', 'integer', 'min:0', 'max:99999'],
        ];
    }
}
