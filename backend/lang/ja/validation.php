<?php

/*
| バリデーションメッセージ（日本語）。使っているルールだけを定義する。
| ルールを追加したら、ここにも文言を足す（足さないと英語の文言が返る）。
*/
return [
    'required' => ':attribute を入力してください',
    'string' => ':attribute は文字列で入力してください',
    'email' => ':attribute は正しいメールアドレスの形式で入力してください',
    'unique' => 'この:attribute はすでに使われています',
    'confirmed' => ':attribute が確認用と一致しません',
    'exists' => '選択された:attribute は正しくありません',
    'in' => '選択された:attribute は正しくありません',
    'boolean' => ':attribute は true / false で指定してください',
    'integer' => ':attribute は整数で入力してください',
    'date' => ':attribute は正しい日付で入力してください',
    'array' => ':attribute は配列で指定してください',
    'file' => ':attribute はファイルを指定してください',
    'max' => [
        'numeric' => ':attribute は :max 以下で入力してください',
        'string' => ':attribute は :max 文字以内で入力してください',
        'array' => ':attribute は :max 個以下で指定してください',
        'file' => ':attribute は :max KB 以下のファイルを指定してください',
    ],
    'min' => [
        'numeric' => ':attribute は :min 以上で入力してください',
        'string' => ':attribute は :min 文字以上で入力してください',
        'array' => ':attribute は :min 個以上で指定してください',
        'file' => ':attribute は :min KB 以上のファイルを指定してください',
    ],
    'between' => [
        'numeric' => ':attribute は :min〜:max の範囲で入力してください',
        'string' => ':attribute は :min〜:max 文字で入力してください',
    ],

    'attributes' => [
        'name' => '名前',
        'email' => 'メールアドレス',
        'password' => 'パスワード',
    ],
];
