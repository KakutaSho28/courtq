<?php

namespace Tests\Feature\Services;

use App\Exceptions\ServiceException;
use App\Models\Booth;
use App\Models\Game;
use App\Models\GameBooth;
use App\Models\ItemStock;
use App\Models\MenuItem;
use App\Models\Venue;
use App\Services\AdminGameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGameServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): AdminGameService
    {
        return app(AdminGameService::class);
    }

    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        return array_merge([
            'season' => '2026-27', 'section' => 5, 'home_team' => 'A チーム', 'away_team' => 'B チーム',
            'venue_id' => Venue::factory()->create()->id, 'tipoff_at' => '2026-11-01 19:05:00', 'status' => 'scheduled',
        ], $override);
    }

    public function test_create_saves_the_game_and_ignores_unknown_fields(): void
    {
        $game = $this->service()->create($this->payload(['id' => 999, 'created_at' => '2000-01-01']));

        $this->assertNotSame(999, $game->id);
        $this->assertSame('A チーム', $game->home_team);
        $this->assertSame(0, $game->game_booths_count);
        $this->assertTrue($game->relationLoaded('venue'));
    }

    public function test_duplicate_game_is_rejected_even_when_section_is_null(): void
    {
        $this->service()->create($this->payload(['section' => null]));

        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('すでに登録されています');
        // MySQL の一意制約は NULL 同士を別物として扱うので、アプリ側で防ぐ
        $this->service()->create($this->payload(['section' => null]));
    }

    public function test_same_teams_in_another_section_are_not_duplicates(): void
    {
        $this->service()->create($this->payload(['section' => 5]));
        $game = $this->service()->create($this->payload(['section' => 6]));

        $this->assertSame(6, $game->section);
        $this->assertSame(2, Game::count());
    }

    public function test_update_does_not_count_the_game_itself_as_a_duplicate(): void
    {
        $game = $this->service()->create($this->payload());

        $updated = $this->service()->update($game, ['tipoff_at' => '2026-11-01 14:05:00', 'status' => 'live']);

        $this->assertSame('live', $updated->status->value);
    }

    public function test_update_to_another_games_key_is_rejected(): void
    {
        $this->service()->create($this->payload(['section' => 5]));
        $other = $this->service()->create($this->payload(['section' => 6]));

        $this->expectException(ServiceException::class);
        $this->service()->update($other, ['section' => 5]);
    }

    public function test_partial_update_keeps_the_other_fields_for_the_duplicate_check(): void
    {
        $this->service()->create($this->payload(['section' => 5, 'home_team' => 'X', 'away_team' => 'Y']));
        $other = $this->service()->create($this->payload(['section' => 5, 'home_team' => 'X', 'away_team' => 'Z']));

        $this->expectException(ServiceException::class);
        $this->service()->update($other, ['away_team' => 'Y']);
    }

    public function test_delete_removes_a_game_without_records_together_with_its_booth_assignments(): void
    {
        $game = Game::factory()->create();
        GameBooth::factory()->for($game)->create();

        $this->service()->delete($game);

        $this->assertModelMissing($game);
        $this->assertSame(0, GameBooth::count());
    }

    public function test_delete_is_refused_when_congestion_was_recorded(): void
    {
        $game = Game::factory()->create();
        GameBooth::factory()->for($game)->withCongestion()->create();

        $this->expectException(ServiceException::class);
        try {
            $this->service()->delete($game);
        } finally {
            $this->assertModelExists($game);
        }
    }

    public function test_delete_is_refused_when_stock_was_recorded(): void
    {
        $game = Game::factory()->create();
        $booth = Booth::factory()->create();
        GameBooth::factory()->for($game)->for($booth)->create();
        $item = MenuItem::factory()->create(['booth_id' => $booth->id]);
        ItemStock::factory()->create(['game_id' => $game->id, 'menu_item_id' => $item->id]);

        $this->expectException(ServiceException::class);
        $this->service()->delete($game);
    }

    public function test_list_puts_upcoming_games_first_in_time_order_then_past_games_newest_first(): void
    {
        $past = Game::factory()->create(['tipoff_at' => now()->subDays(10)]);
        $older = Game::factory()->create(['tipoff_at' => now()->subDays(20)]);
        $later = Game::factory()->create(['tipoff_at' => now()->addDays(9)]);
        $soon = Game::factory()->create(['tipoff_at' => now()->addDays(2)]);

        $ids = $this->service()->list()->pluck('id')->all();

        $this->assertSame([$soon->id, $later->id, $past->id, $older->id], $ids);
    }

    public function test_list_includes_the_number_of_assigned_booths(): void
    {
        $game = Game::factory()->create();
        GameBooth::factory()->count(2)->for($game)->create();

        $this->assertSame(2, $this->service()->list()->firstWhere('id', $game->id)->game_booths_count);
    }
}
