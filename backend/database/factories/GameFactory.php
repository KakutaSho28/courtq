<?php

namespace Database\Factories;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Game> */
class GameFactory extends Factory
{
    public function definition(): array
    {
        return [
            'season' => '2026-27',
            'section' => fake()->unique()->numberBetween(1, 40),
            'home_team' => 'ホームチーム'.fake()->unique()->numberBetween(1, 9999),
            'away_team' => 'アウェイチーム'.fake()->unique()->numberBetween(1, 9999),
            'venue_id' => Venue::factory(),
            'tipoff_at' => now()->addDays(fake()->numberBetween(1, 30))->setTime(19, 5),
            'status' => GameStatus::Scheduled,
        ];
    }

    /** 今日開催（アプリのタイムゾーンの今日 19:05 開始） */
    public function today(): static
    {
        return $this->state(fn () => ['tipoff_at' => now()->setTime(19, 5), 'status' => GameStatus::Live]);
    }
}
