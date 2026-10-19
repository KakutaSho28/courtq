<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** 試合に出店するブースの一覧（この内容にそろえる）。認可はルートの role:admin */
class SyncGameBoothsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'booths' => ['present', 'array', 'max:200'],
            'booths.*.booth_id' => ['required', 'integer', 'distinct', Rule::exists('booths', 'id')],
            'booths.*.is_open' => ['sometimes', 'boolean'],
        ];
    }
}
