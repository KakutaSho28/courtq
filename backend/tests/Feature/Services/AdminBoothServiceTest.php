<?php

namespace Tests\Feature\Services;

use App\Exceptions\ServiceException;
use App\Models\Booth;
use App\Models\Game;
use App\Models\GameBooth;
use App\Models\MenuItem;
use App\Models\User;
use App\Services\AdminBoothService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminBoothServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): AdminBoothService
    {
        return app(AdminBoothService::class);
    }

    public function test_create_ignores_unknown_fields(): void
    {
        $booth = $this->service()->create(['name' => 'ポップコーン', 'category' => 'food', 'location_label' => '1F 西', 'description' => null, 'id' => 99]);

        $this->assertNotSame(99, $booth->id);
        $this->assertSame(0, $booth->staff_count);
        $this->assertSame(0, $booth->game_booths_count);
    }

    public function test_list_counts_staff_and_games_and_sorts_by_name(): void
    {
        $b = Booth::factory()->create(['name' => 'B ブース']);
        $a = Booth::factory()->create(['name' => 'A ブース']);
        $a->staff()->attach(User::factory()->count(2)->create()->modelKeys());
        GameBooth::factory()->for($a)->for(Game::factory())->create();

        $list = $this->service()->list();

        $this->assertSame([$a->id, $b->id], $list->pluck('id')->all());
        $this->assertSame(2, $list->first()->staff_count);
        $this->assertSame(1, $list->first()->game_booths_count);
    }

    public function test_update_clears_the_list_cache_of_every_game_the_booth_is_in(): void
    {
        $booth = Booth::factory()->create();
        $game = Game::factory()->create();
        GameBooth::factory()->for($game)->for($booth)->create();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/games/{$game->id}/booths")->assertOk(); // キャッシュを作る
        $this->assertTrue(Cache::has("courtq:game:{$game->id}:booth-list"));

        $this->service()->update($booth, ['name' => '新しい名前', 'category' => 'merch']);

        $this->assertFalse(Cache::has("courtq:game:{$game->id}:booth-list"));
        $this->assertSame('新しい名前', $booth->fresh()->name);
    }

    public function test_delete_removes_an_unassigned_booth_with_its_menu_and_staff(): void
    {
        $booth = Booth::factory()->create();
        MenuItem::factory()->create(['booth_id' => $booth->id]);
        $booth->staff()->attach(User::factory()->create()->id);

        $this->service()->delete($booth);

        $this->assertModelMissing($booth);
        $this->assertSame(0, MenuItem::count());
        $this->assertSame(0, \DB::table('booth_staff')->count());
    }

    public function test_delete_is_refused_when_the_booth_is_assigned_to_a_game(): void
    {
        $booth = Booth::factory()->create();
        GameBooth::factory()->for($booth)->create();

        $this->expectException(ServiceException::class);
        try {
            $this->service()->delete($booth);
        } finally {
            $this->assertModelExists($booth);
        }
    }
}
