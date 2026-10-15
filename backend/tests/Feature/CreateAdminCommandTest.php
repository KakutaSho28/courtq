<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_new_admin_and_prints_a_generated_password_once(): void
    {
        $this->artisan('app:create-admin', ['email' => 'boss@example.com'])
            ->expectsOutputToContain('管理者を作成しました')
            ->expectsOutputToContain('この 1 回しか表示されません')
            ->assertSuccessful();

        $admin = User::where('email', 'boss@example.com')->first();
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertSame('boss', $admin->name);
    }

    public function test_generated_password_works_for_login(): void
    {
        $this->artisan('app:create-admin', ['email' => 'boss@example.com', '--password' => 'password123', '--name' => 'ボス'])->assertSuccessful();

        $this->postJson('/api/auth/login', ['email' => 'boss@example.com', 'password' => 'password123'])->assertOk();
        $this->assertSame('ボス', User::where('email', 'boss@example.com')->value('name'));
    }

    public function test_promotes_an_existing_user_without_touching_the_password(): void
    {
        $user = User::factory()->create(['email' => 'a@example.com', 'password' => 'password123']);
        $hash = $user->password;

        $this->artisan('app:create-admin', ['email' => 'a@example.com'])->expectsOutputToContain('既存のユーザーを管理者にしました')->assertSuccessful();

        $this->assertSame(UserRole::Admin, $user->fresh()->role);
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertTrue(Hash::check('password123', $user->fresh()->password));
    }

    public function test_rejects_an_invalid_email_and_a_short_password(): void
    {
        $this->artisan('app:create-admin', ['email' => 'not-an-email'])->assertFailed();
        $this->artisan('app:create-admin', ['email' => 'a@example.com', '--password' => 'short'])->assertFailed();

        $this->assertSame(0, User::count());
    }
}
