<?php

namespace Database\Factories;

use App\Enums\CongestionLevel;
use App\Models\Booth;
use App\Models\Game;
use App\Models\GameBooth;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GameBooth> */
class GameBoothFactory extends Factory
{
    /** 既定は「まだ誰も更新していない」状態（混雑は null） */
    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'booth_id' => Booth::factory(),
            'is_open' => true,
            'congestion_level' => null,
            'wait_minutes' => null,
            'congestion_updated_at' => null,
            'congestion_updated_by' => null,
        ];
    }

    public function withCongestion(CongestionLevel $level = CongestionLevel::Slightly, ?int $waitMinutes = 5, ?\DateTimeInterface $at = null): static
    {
        return $this->state(fn () => [
            'congestion_level' => $level,
            'wait_minutes' => $waitMinutes,
            'congestion_updated_at' => $at ?? now(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['is_open' => false]);
    }
}
