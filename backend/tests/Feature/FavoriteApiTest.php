<?php

namespace Tests\Feature;

use App\Models\Booth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FavoriteApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_favorite_endpoints_require_login(): void
    {
        $booth = Booth::factory()->create();

        $this->getJson('/api/me/favorites')->assertUnauthorized();
        $this->putJson("/api/booths/{$booth->id}/favorite")->assertUnauthorized();
        $this->deleteJson("/api/booths/{$booth->id}/favorite")->assertUnauthorized();
    }

    public function test_any_role_can_add_list_and_remove_favorites(): void
    {
        foreach (['user', 'staff', 'admin'] as $role) {
            $user = User::factory()->create();
            $user->forceFill(['role' => $role])->save();
            $booth = Booth::factory()->create();
            Sanctum::actingAs($user);

            $this->putJson("/api/booths/{$booth->id}/favorite")->assertOk()->assertJson(['data' => ['booth_id' => $booth->id, 'is_favorite' => true]]);
            $this->getJson('/api/me/favorites')->assertOk()->assertJsonPath('data.0.booth_id', $booth->id);
            $this->deleteJson("/api/booths/{$booth->id}/favorite")->assertOk()->assertJson(['data' => ['is_favorite' => false]]);
            $this->getJson('/api/me/favorites')->assertOk()->assertJsonCount(0, 'data');
        }
    }

    public function test_put_and_delete_are_idempotent(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $booth = Booth::factory()->create();

        $this->putJson("/api/booths/{$booth->id}/favorite")->assertOk();
        $this->putJson("/api/booths/{$booth->id}/favorite")->assertOk();
        $this->assertSame(1, $user->favoriteBooths()->count());

        $this->deleteJson("/api/booths/{$booth->id}/favorite")->assertOk();
        $this->deleteJson("/api/booths/{$booth->id}/favorite")->assertOk();
        $this->assertSame(0, $user->favoriteBooths()->count());
    }

    public function test_favorites_of_other_users_are_never_visible_or_changed(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $booth = Booth::factory()->create();
        $alice->favoriteBooths()->attach($booth->id);

        Sanctum::actingAs($bob);
        $this->getJson('/api/me/favorites')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson("/api/booths/{$booth->id}/favorite")->assertOk();

        $this->assertSame(1, $alice->favoriteBooths()->count(), 'bob の操作で alice のお気に入りが消えてはいけない');
    }

    public function test_unknown_booth_is_404(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/booths/999999/favorite')->assertNotFound()->assertJson(['message' => '見つかりません']);
        $this->deleteJson('/api/booths/999999/favorite')->assertNotFound();
    }

    public function test_favorites_list_has_the_booth_fields(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $booth = Booth::factory()->create(['name' => 'から揚げ屋', 'location_label' => '1F コンコース西']);
        $user->favoriteBooths()->attach($booth->id);

        $this->getJson('/api/me/favorites')->assertOk()->assertJson(['data' => [[
            'booth_id' => $booth->id, 'name' => 'から揚げ屋', 'category' => 'food', 'location_label' => '1F コンコース西',
        ]]]);
    }
}
