<?php

namespace App\Services;

use App\Exceptions\ServiceException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * 認証の業務ロジック。ログインの入口はここに集約している。
 *
 * 外部 ID プロバイダ（SSO / OAuth。例: リーグのアカウント）を後から足すときは、この Service に
 * `loginWithProvider(string $provider, string $providerUserId, array $profile)` のようなメソッドを追加し、
 * users.provider / users.provider_user_id（(provider, provider_user_id) で一意）でユーザーを引き当てるか新規作成して、
 * issueToken() で同じ Sanctum トークンを返す。Controller・認可（role / Policy）・フロントのトークン処理は変えずに済む。
 * 外部ログインのユーザーは、役割も常にサーバー側で決める（プロバイダから受け取った値で role を決めない）。
 */
class AuthService
{
    /**
     * @param  array{name: string, email: string, password: string}  $data
     * @return array{user: User, token: string}
     */
    public function register(array $data): array
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        return ['user' => $user, 'token' => $this->issueToken($user)];
    }

    /**
     * @return array{user: User, token: string}
     */
    public function login(string $email, string $password): array
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw new ServiceException('メールアドレスまたはパスワードが正しくありません', 401);
        }

        // 多重ログイン防止のため既存トークンを削除
        $user->tokens()->delete();

        return ['user' => $user, 'token' => $this->issueToken($user)];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }

    private function issueToken(User $user): string
    {
        return $user->createToken('courtq')->plainTextToken;
    }
}
