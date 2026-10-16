<?php

namespace Database\Factories;

use App\Enums\StockStatus;
use App\Models\Game;
use App\Models\ItemStock;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ItemStock> */
class ItemStockFactory extends Factory
{
    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'menu_item_id' => MenuItem::factory(),
            'status' => StockStatus::InStock,
            'quantity' => null,
            'updated_by' => null,
        ];
    }

    public function status(StockStatus $status, ?int $quantity = null): static
    {
        return $this->state(fn () => ['status' => $status, 'quantity' => $quantity]);
    }
}
