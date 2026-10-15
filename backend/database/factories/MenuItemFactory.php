<?php

namespace Database\Factories;

use App\Enums\MenuItemKind;
use App\Models\Booth;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MenuItem> */
class MenuItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'booth_id' => Booth::factory(),
            'name' => fake()->unique()->word(),
            'kind' => MenuItemKind::Food,
            'price' => fake()->numberBetween(2, 15) * 100,
            'description' => null,
            'is_active' => true,
        ];
    }

    public function merch(): static
    {
        return $this->state(fn () => ['kind' => MenuItemKind::Merch]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
