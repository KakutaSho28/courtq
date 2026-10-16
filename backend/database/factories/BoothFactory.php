<?php

namespace Database\Factories;

use App\Enums\BoothCategory;
use App\Models\Booth;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Booth> */
class BoothFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word().'ブース',
            'category' => BoothCategory::Food,
            'location_label' => '2F コンコース東',
            'description' => null,
        ];
    }

    public function merch(): static
    {
        return $this->state(fn () => ['category' => BoothCategory::Merch]);
    }

    public function both(): static
    {
        return $this->state(fn () => ['category' => BoothCategory::Both]);
    }
}
