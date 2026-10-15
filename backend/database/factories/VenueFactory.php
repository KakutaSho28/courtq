<?php

namespace Database\Factories;

use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Venue> */
class VenueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city().'アリーナ',
            'address' => fake()->address(),
            'map_note' => '飲食ブースは 1F・2F のコンコースにあります。',
        ];
    }
}
