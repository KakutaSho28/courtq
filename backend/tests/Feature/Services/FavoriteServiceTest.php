<?php

namespace Tests\Feature\Services;

use App\Models\Booth;
use App\Models\User;
use App\Services\FavoriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FavoriteServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_is_idempotent(): void
    {
        $user = User::factory()->create();
        $booth = Booth::factory()->create();
        $service = app(FavoriteService::class);

        $service->add($user, $booth);
        $service->add($user, $booth);

        $this->assertSame(1, $user->favoriteBooths()->count());
    }

    public function test_remove_is_idempotent(): void
    {
        $user = User::factory()->create();
        $booth = Booth::factory()->create();
        $service = app(FavoriteService::class);
        $service->add($user, $booth);

        $service->remove($user, $booth);
        $service->remove($user, $booth);

        $this->assertSame(0, $user->favoriteBooths()->count());
    }

    public function test_list_returns_only_own_favorites_sorted_by_name(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $b = Booth::factory()->create(['name' => 'B ブース']);
        $a = Booth::factory()->create(['name' => 'A ブース']);
        $notMine = Booth::factory()->create();
        $service = app(FavoriteService::class);
        $service->add($user, $b);
        $service->add($user, $a);
        $service->add($other, $notMine);

        $this->assertSame([$a->id, $b->id], $service->list($user)->pluck('id')->all());
    }
}
