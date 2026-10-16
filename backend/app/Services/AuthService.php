<?php

namespace App\Services;

use App\Exceptions\ServiceException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

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
