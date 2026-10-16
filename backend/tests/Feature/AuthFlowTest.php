<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_login_me_and_logout(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'テストユーザー',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated()->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email'], 'token']]);

        $token = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ])->assertOk()->json('data.token');

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertOk()
            ->assertExactJson(['data' => ['id' => 1, 'name' => 'テストユーザー', 'email' => 'test@example.com', 'role' => 'user', 'booths' => []]]);

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->assertSame(0, User::first()->tokens()->count());
    }

    public function test_protected_routes_return_japanese_401_json(): void
    {
        $this->getJson('/api/me')->assertUnauthorized()->assertExactJson(['message' => 'ログインが必要です']);
        // Accept ヘッダーが無くても /api は JSON で返す（ログイン画面へのリダイレクトにしない）
        $this->get('/api/me')->assertUnauthorized()->assertExactJson(['message' => 'ログインが必要です']);
    }

    public function test_wrong_password_returns_401(): void
    {
        User::factory()->create(['email' => 'a@example.com', 'password' => Hash::make('password123')]);

        $this->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => 'wrong-password'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'メールアドレスまたはパスワードが正しくありません']);
    }

    public function test_validation_error_is_japanese_422(): void
    {
        $this->postJson('/api/auth/register', ['name' => '', 'email' => 'not-an-email', 'password' => 'short'])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['name', 'email', 'password']])
            ->assertJsonPath('errors.email.0', 'メールアドレス は正しいメールアドレスの形式で入力してください');
    }

    public function test_unknown_route_returns_japanese_404_json(): void
    {
        $this->getJson('/api/nothing-here')->assertNotFound()->assertExactJson(['message' => '見つかりません']);
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => 'wrong-password'])->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => 'wrong-password'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'リクエストが多すぎます。しばらくしてからもう一度お試しください');
    }

    public function test_json_keeps_japanese_unescaped_to_keep_payloads_small(): void
    {
        // エラー（Handler 経由）と成功のどちらも、\uXXXX ではなく日本語のまま返る
        $error = $this->getJson('/api/me')->assertUnauthorized();
        $this->assertStringContainsString('ログインが必要です', $error->getContent());
        $this->assertStringNotContainsString('\\u', $error->getContent());

        $this->postJson('/api/auth/register', ['name' => 'アリス', 'email' => 'alice@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])
            ->assertCreated()
            ->assertSee('アリス', false);
    }
}
