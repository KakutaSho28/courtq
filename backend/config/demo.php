<?php

/*
| 公開デモ用のシードデータ（php artisan db:seed）の設定。
| env() はここでだけ読む（本番の config:cache 後は、アプリコードから env() を読むと null になる）。
*/
return [

    // デモアカウントのパスワード。公開デモなので誰でも知る前提の値。本番で変えたい場合は環境変数で上書きする
    'password' => env('DEMO_PASSWORD', 'courtq-demo-1234'),

];
