<?php

namespace App\Services;

use App\Models\Venue;
use Illuminate\Database\Eloquent\Collection;

/** 会場（試合の登録フォームと CSV 取り込みの会場名の照合に使う） */
class VenueService
{
    /** @return Collection<int, Venue> */
    public function list(): Collection
    {
        return Venue::query()->orderBy('name')->get();
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): Venue
    {
        return Venue::create([
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'map_note' => $data['map_note'] ?? null,
        ]);
    }
}
