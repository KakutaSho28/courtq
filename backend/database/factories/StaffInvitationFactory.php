<?php

namespace Database\Factories;

use App\Models\Booth;
use App\Models\StaffInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StaffInvitation> */
class StaffInvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'booth_id' => Booth::factory(),
            'code' => Str::upper(Str::random(10)),
            'expires_at' => now()->addDays(7),
            'used_at' => null,
            'used_by' => null,
            'created_by' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }

    public function used(): static
    {
        return $this->state(fn () => ['used_at' => now()]);
    }
}
