<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 試合の CSV 取り込み。認可はルートの role:admin */
class ImportGamesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 1MB まで（500 行の試合表に十分）。CSV の MIME は環境で text/plain などにぶれるので、拡張子で見る
            'file' => ['required', 'file', 'max:1024', 'extensions:csv,txt'],
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }
}
