<?php

namespace Tests\Feature\Services;

use App\Models\Game;
use App\Services\GameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GameServiceTest extends TestCase
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

    private function gameAt(string $datetime): Game
    {
        return Game::factory()->create(['tipoff_at' => Carbon::parse($datetime, 'Asia/Tokyo')]);
    }

    public function test_default_list_starts_from_today_and_excludes_past_games(): void
    {
        $this->gameAt('2026-10-15 19:05'); // 昨日
        $today = $this->gameAt('2026-10-16 19:05');
        $next = $this->gameAt('2026-10-20 14:05');

        $ids = app(GameService::class)->list()->pluck('id')->all();

        $this->assertSame([$today->id, $next->id], $ids);
    }

    public function test_today_game_comes_first_even_when_from_includes_past_games(): void
    {
        $past = $this->gameAt('2026-10-10 19:05');
        $today = $this->gameAt('2026-10-16 19:05');
        $next = $this->gameAt('2026-10-20 14:05');

        $ids = app(GameService::class)->list(from: Carbon::parse('2026-10-01', 'Asia/Tokyo'))->pluck('id')->all();

        $this->assertSame([$today->id, $past->id, $next->id], $ids);
    }

    public function test_date_filter_returns_only_that_day_in_app_timezone(): void
    {
        $this->gameAt('2026-10-20 00:30'); // 日本時間の 20 日の早朝（UTC では 19 日）
        $late = $this->gameAt('2026-10-20 23:30');
        $this->gameAt('2026-10-21 00:30');

        $games = app(GameService::class)->list(date: Carbon::parse('2026-10-20', 'Asia/Tokyo'));

        $this->assertCount(2, $games);
        $this->assertTrue($games->pluck('id')->contains($late->id));
    }

    public function test_from_filter_excludes_earlier_games(): void
    {
        $this->gameAt('2026-10-16 19:05');
        $later = $this->gameAt('2026-10-25 19:05');

        $games = app(GameService::class)->list(from: Carbon::parse('2026-10-21', 'Asia/Tokyo'));

        $this->assertSame([$later->id], $games->pluck('id')->all());
    }

    public function test_venue_is_eager_loaded(): void
    {
        $this->gameAt('2026-10-16 19:05');

        $this->assertTrue(app(GameService::class)->list()->first()->relationLoaded('venue'));
    }
}
