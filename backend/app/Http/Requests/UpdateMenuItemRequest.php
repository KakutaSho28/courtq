<?php

namespace App\Http\Requests;

use App\Enums\MenuItemKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** 部分更新。送った項目だけを変える */
class UpdateMenuItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // 認可はルートの can:manage,booth（Policy）と、ブースに限った ID の解決（scopeBindings）で済ませている
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'kind' => ['sometimes', 'required', 'string', Rule::enum(MenuItemKind::class)],
            'price' => ['sometimes', 'required', 'integer', 'min:0', 'max:1000000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
