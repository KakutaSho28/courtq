<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-16 12:00:00', 'Asia/Tokyo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_games_require_login(): void
    {
        $game = Game::factory()->create();

        $this->getJson('/api/games')->assertUnauthorized()->assertJson(['message' => 'ログインが必要です']);
        $this->getJson("/api/games/{$game->id}")->assertUnauthorized();
    }

    public function test_index_lists_today_first_with_venue_and_iso_datetime_with_offset(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Game::factory()->create(['tipoff_at' => Carbon::parse('2026-10-20 14:05', 'Asia/Tokyo')]);
        $today = Game::factory()->today()->create();

        $response = $this->getJson('/api/games')->assertOk();

        $response->assertJsonPath('data.0.id', $today->id)
            ->assertJsonPath('data.0.tipoff_at', '2026-10-16T19:05:00+09:00')
            ->assertJsonPath('data.0.venue.name', $today->venue->name)
            ->assertJsonPath('data.0.status', 'live')
            ->assertJsonCount(2, 'data');
    }

    public function test_index_filters_by_date_and_from(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Game::factory()->create(['tipoff_at' => Carbon::parse('2026-10-16 19:05', 'Asia/Tokyo')]);
        $target = Game::factory()->create(['tipoff_at' => Carbon::parse('2026-10-24 14:05', 'Asia/Tokyo')]);

        $this->getJson('/api/games?date=2026-10-24')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
        $this->getJson('/api/games?from=2026-10-20')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
    }

    public function test_index_rejects_invalid_dates_in_japanese(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/games?date=2026/10/24')
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', '日付 は Y-m-d の形式で入力してください');
        $this->getJson('/api/games?from=あした')->assertUnprocessable()->assertJsonValidationErrors('from');
    }

    public function test_show_returns_the_game(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $game = Game::factory()->create(['season' => '2026-27', 'section' => 5]);

        $this->getJson("/api/games/{$game->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $game->id)
            ->assertJsonPath('data.season', '2026-27')
            ->assertJsonPath('data.section', 5)
            ->assertJsonPath('data.home_team', $game->home_team);
    }

    public function test_show_unknown_game_is_404_in_japanese(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/games/999999')->assertNotFound()->assertJson(['message' => '見つかりません']);
    }
}
