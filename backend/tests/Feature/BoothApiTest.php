<?php

namespace Tests\Feature;

use App\Enums\CongestionLevel;
use App\Enums\StockStatus;
use App\Models\Booth;
use App\Models\CongestionLog;
use App\Models\Game;
use App\Models\GameBooth;
use App\Models\ItemStock;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BoothApiTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-16 19:30:00', 'Asia/Tokyo'));
        $this->game = Game::factory()->today()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function gameBooth(string $name, ?CongestionLevel $level = null, ?int $wait = null, ?Carbon $updatedAt = null): GameBooth
    {
        $factory = GameBooth::factory()->for($this->game)->for(Booth::factory()->create(['name' => $name]));

        return ($level ? $factory->withCongestion($level, $wait, $updatedAt) : $factory)->create();
    }

    public function test_every_booth_endpoint_requires_login(): void
    {
        $gb = $this->gameBooth('A');
        $base = "/api/games/{$this->game->id}/booths";

        $this->getJson($base)->assertUnauthorized();
        $this->getJson("{$base}/{$gb->booth_id}")->assertUnauthorized();
        $this->getJson("{$base}/{$gb->booth_id}/congestion-history")->assertUnauthorized();
    }

    public function test_index_returns_congestion_with_label_wait_and_freshness(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->gameBooth('A', CongestionLevel::Crowded, 25, now()->subMinutes(3));

        $this->getJson("/api/games/{$this->game->id}/booths")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'A')
            ->assertJsonPath('data.0.congestion.level', 3)
            ->assertJsonPath('data.0.congestion.label', '混雑')
            ->assertJsonPath('data.0.congestion.wait_minutes', 25)
            ->assertJsonPath('data.0.congestion.updated_at', '2026-10-16T19:27:00+09:00')
            ->assertJsonPath('data.0.congestion.is_stale', false)
            ->assertJsonPath('data.0.is_open', true)
            ->assertJsonPath('meta.stale_after_minutes', 15);
    }

    public function test_index_does_not_include_menu_details(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $gb = $this->gameBooth('A');
        MenuItem::factory()->create(['booth_id' => $gb->booth_id]);

        $item = $this->getJson("/api/games/{$this->game->id}/booths")->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('menu_items', $item);
        $this->assertArrayNotHasKey('description', $item);
    }

    public function test_freshness_boundary_is_exactly_the_configured_threshold(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->gameBooth('ちょうど 15 分', CongestionLevel::Empty, 0, now()->subMinutes(15));
        $this->gameBooth('15 分 1 秒', CongestionLevel::Empty, 0, now()->subMinutes(15)->subSecond());

        $flags = collect($this->getJson("/api/games/{$this->game->id}/booths")->json('data'))->pluck('congestion.is_stale', 'name');

        $this->assertFalse($flags['ちょうど 15 分']);
        $this->assertTrue($flags['15 分 1 秒']);
    }

    public function test_freshness_threshold_comes_from_config(): void
    {
        Sanctum::actingAs(User::factory()->create());
        config(['courtq.stale_after_minutes' => 5]);
        $this->gameBooth('A', CongestionLevel::Empty, 0, now()->subMinutes(6));

        $this->getJson("/api/games/{$this->game->id}/booths")
            ->assertJsonPath('data.0.congestion.is_stale', true)
            ->assertJsonPath('meta.stale_after_minutes', 5);
    }

    public function test_booth_without_any_update_has_null_congestion_and_is_not_stale(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->gameBooth('未更新');

        $this->getJson("/api/games/{$this->game->id}/booths")
            ->assertJsonPath('data.0.congestion.level', null)
            ->assertJsonPath('data.0.congestion.label', null)
            ->assertJsonPath('data.0.congestion.updated_at', null)
            ->assertJsonPath('data.0.congestion.is_stale', false);
    }

    public function test_index_sort_filter_and_favorites_via_query_string(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $busy = $this->gameBooth('混んでいる', CongestionLevel::VeryCrowded, 50);
        $this->gameBooth('空いている', CongestionLevel::Empty, 0);
        $user->favoriteBooths()->attach($busy->booth_id);
        $url = "/api/games/{$this->game->id}/booths";

        $this->assertSame(['空いている', '混んでいる'], $this->getJson("{$url}?sort=congestion")->json('data.*.name'));
        $this->assertSame(['空いている', '混んでいる'], $this->getJson("{$url}?sort=wait")->json('data.*.name'));
        $this->assertSame(['混んでいる'], $this->getJson("{$url}?favorites=1")->json('data.*.name'));
        $this->assertSame([], $this->getJson("{$url}?in_stock=1")->json('data'));
        $this->assertSame(['混んでいる', '空いている'], $this->getJson($url)->json('data.*.name'), '並び順を指定しないときは名前順');
    }

    public function test_index_category_filter(): void
    {
        Sanctum::actingAs(User::factory()->create());
        GameBooth::factory()->for($this->game)->for(Booth::factory()->merch()->create(['name' => '物販']))->create();
        GameBooth::factory()->for($this->game)->for(Booth::factory()->create(['name' => '飲食']))->create();

        $this->assertSame(['物販'], $this->getJson("/api/games/{$this->game->id}/booths?category=merch")->json('data.*.name'));
    }

    public function test_index_rejects_invalid_parameters(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $url = "/api/games/{$this->game->id}/booths";

        $this->getJson("{$url}?sort=random")->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson("{$url}?category=drinks")->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->getJson("{$url}?in_stock=maybe")->assertUnprocessable()->assertJsonValidationErrors('in_stock');
        $this->getJson("{$url}?favorites=2")->assertUnprocessable()->assertJsonValidationErrors('favorites');
    }

    public function test_index_of_unknown_game_is_404(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/games/999999/booths')->assertNotFound()->assertJson(['message' => '見つかりません']);
    }

    public function test_index_query_count_does_not_grow_with_the_number_of_booths(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->gameBooth('A', CongestionLevel::Empty, 0);
        $this->getJson("/api/games/{$this->game->id}/booths"); // ウォームアップ

        $count = function (): int {
            \Cache::flush();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson("/api/games/{$this->game->id}/booths")->assertOk();

            return count(DB::getQueryLog());
        };
        $few = $count();
        foreach (range(1, 30) as $i) {
            $this->gameBooth("ブース{$i}", CongestionLevel::Slightly, 5);
        }
        $many = $count();

        $this->assertSame($few, $many, 'ブース数が増えるとクエリ数も増えています（N+1）');
    }

    public function test_second_request_is_served_from_cache_without_hitting_booth_tables(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->gameBooth('A', CongestionLevel::Empty, 0);
        $this->getJson("/api/games/{$this->game->id}/booths")->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson("/api/games/{$this->game->id}/booths")->assertOk();

        $tables = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'game_booths') || str_contains($q, 'item_stocks'));
        $this->assertCount(0, $tables);
    }

    public function test_show_returns_menu_with_stock_and_freshness(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $gb = $this->gameBooth('A', CongestionLevel::Slightly, 8, now()->subMinute());
        $food = MenuItem::factory()->create(['booth_id' => $gb->booth_id, 'name' => 'から揚げ', 'price' => 700]);
        $goods = MenuItem::factory()->merch()->create(['booth_id' => $gb->booth_id, 'name' => 'タオル', 'price' => 2200]);
        MenuItem::factory()->inactive()->create(['booth_id' => $gb->booth_id, 'name' => '販売終了']);
        $stock = ItemStock::factory()->for($this->game)->for($food, 'menuItem')->status(StockStatus::Low, 4)->create();
        $stock->forceFill(['updated_at' => now()->subMinutes(30)])->saveQuietly();

        $response = $this->getJson("/api/games/{$this->game->id}/booths/{$gb->booth_id}")->assertOk();

        $response->assertJsonPath('data.name', 'A')
            ->assertJsonPath('data.congestion.label', 'やや混雑')
            ->assertJsonPath('data.menu_items.0.name', 'から揚げ')
            ->assertJsonPath('data.menu_items.0.kind', 'food')
            ->assertJsonPath('data.menu_items.0.price', 700)
            ->assertJsonPath('data.menu_items.0.stock.status', 'low')
            ->assertJsonPath('data.menu_items.0.stock.label', '残りわずか')
            ->assertJsonPath('data.menu_items.0.stock.quantity', 4)
            ->assertJsonPath('data.menu_items.0.stock.is_stale', true)
            ->assertJsonPath('data.menu_items.1.name', 'タオル')
            ->assertJsonPath('data.menu_items.1.stock', null)
            ->assertJsonPath('data.is_favorite', false)
            ->assertJsonPath('meta.stale_after_minutes', 15)
            ->assertJsonCount(2, 'data.menu_items');
        $this->assertSame($goods->id, $response->json('data.menu_items.1.id'));
    }

    public function test_show_of_a_booth_that_is_not_in_the_game_is_404_in_japanese(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $other = Booth::factory()->create();

        $this->getJson("/api/games/{$this->game->id}/booths/{$other->id}")
            ->assertNotFound()
            ->assertJson(['message' => 'この試合に出店していないブースです']);
        $this->getJson("/api/games/{$this->game->id}/booths/999999")->assertNotFound();
    }

    public function test_congestion_history_returns_points_without_the_updater(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $gb = $this->gameBooth('A');
        $staff = User::factory()->create();
        // 新しい記録を先に作っても、返るのは古い順（created_at は fillable ではないので forceFill で指定する）
        (new CongestionLog)->forceFill(['game_booth_id' => $gb->id, 'level' => 3, 'wait_minutes' => 15, 'user_id' => $staff->id, 'created_at' => now()->subMinutes(5)])->save();
        (new CongestionLog)->forceFill(['game_booth_id' => $gb->id, 'level' => 1, 'wait_minutes' => 0, 'user_id' => $staff->id, 'created_at' => now()->subMinutes(20)])->save();

        $response = $this->getJson("/api/games/{$this->game->id}/booths/{$gb->booth_id}/congestion-history")->assertOk();

        $response->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.level', 1)
            ->assertJsonPath('data.1.label', '混雑')
            ->assertJsonPath('data.1.wait_minutes', 15)
            ->assertJsonPath('data.1.recorded_at', '2026-10-16T19:25:00+09:00');
        $this->assertArrayNotHasKey('user_id', $response->json('data.0'));
    }

    public function test_congestion_history_of_a_booth_not_in_the_game_is_404(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $other = Booth::factory()->create();

        $this->getJson("/api/games/{$this->game->id}/booths/{$other->id}/congestion-history")->assertNotFound();
    }
}
