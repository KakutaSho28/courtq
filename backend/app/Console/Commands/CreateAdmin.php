<?php

namespace App\Console\Commands;

use App\Services\AdminUserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {email : 管理者にするユーザーのメールアドレス} {--name= : 新規作成するときの表示名（既定はメールの @ より前）} {--password= : 新規作成するときのパスワード（省略すると 16 文字を生成して 1 回だけ表示する）}';

    protected $description = '管理者アカウントを作成する（既存のユーザーならそのユーザーを管理者にする）';

    public function handle(AdminUserService $service): int
    {
        $validator = Validator::make(['email' => $this->argument('email')], ['email' => ['required', 'email']]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $password = $this->option('password');
        if ($password !== null && strlen($password) < 8) {
            $this->error('パスワードは 8 文字以上で指定してください');

            return self::FAILURE;
        }

        $result = $service->createOrPromote($this->argument('email'), $this->option('name'), $password);

        if ($result['created']) {
            $this->info("管理者を作成しました: {$result['user']->email}");
            if ($this->option('password') === null) {
                $this->warn("パスワード（この 1 回しか表示されません）: {$result['password']}");
            }
        } else {
            $this->info("既存のユーザーを管理者にしました: {$result['user']->email}");
        }

        return self::SUCCESS;
    }
}
