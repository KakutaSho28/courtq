<?php

namespace App\Http\Requests;

use App\Enums\BoothCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListBoothsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => ['nullable', Rule::enum(BoothCategory::class)],
            'sort' => ['nullable', 'in:congestion,wait,name'],
            'in_stock' => ['nullable', 'boolean'],
            'favorites' => ['nullable', 'boolean'],
        ];
    }
}
