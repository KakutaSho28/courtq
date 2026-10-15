<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 管理者アカウントの作成・昇格。管理者は画面や API からは作れず、artisan コマンド（app:create-admin）とデモ Seeder だけが作る。
 */
class AdminUserService
{
    /**
     * メールアドレスのユーザーを管理者にする。いなければ新規作成する（その場合のパスワードは返り値でだけ分かる）。
     *
     * @return array{user: User, created: bool, password: string|null}
     */
    public function createOrPromote(string $email, ?string $name = null, ?string $password = null): array
    {
        $user = User::where('email', $email)->first();

        if ($user) {
            $user->forceFill(['role' => UserRole::Admin])->save();

            return ['user' => $user, 'created' => false, 'password' => null];
        }

        $password ??= Str::password(16, symbols: false);
        $user = new User(['name' => $name ?: Str::before($email, '@'), 'email' => $email, 'password' => $password]);
        $user->forceFill(['role' => UserRole::Admin])->save();

        return ['user' => $user, 'created' => true, 'password' => $password];
    }
}
