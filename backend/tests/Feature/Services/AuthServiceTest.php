<?php

namespace Tests\Feature\Services;

use App\Exceptions\ServiceException;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_hashes_password_and_issues_token(): void
    {
        $result = app(AuthService::class)->register(['name' => 'A', 'email' => 'a@example.com', 'password' => 'password123']);

        $this->assertTrue(Hash::check('password123', $result['user']->password));
        $this->assertNotEmpty($result['token']);
    }

    public function test_login_rotates_existing_tokens(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);
        $user->createToken('old');

        app(AuthService::class)->login($user->email, 'password123');

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_login_with_wrong_password_throws_401(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);

        try {
            app(AuthService::class)->login($user->email, 'wrong');
            $this->fail('ServiceException expected');
        } catch (ServiceException $e) {
            $this->assertSame(401, $e->status);
        }
    }
}
