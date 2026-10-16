<?php

namespace App\Events;

use App\Models\ItemStock;
use App\Models\MenuItem;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * メニューの在庫が更新された。試合ごとの公開チャンネル `game.{gameId}` に、キュー経由で配信する。
 * ペイロードの考え方は BoothCongestionUpdated と同じ（小さく、更新した時点の値）。
 */
class ItemStockUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public const NAME = 'item.stock.updated';

    public function __construct(
        public readonly int $gameId,
        public readonly int $boothId,
        public readonly int $menuItemId,
        public readonly string $status,
        public readonly string $label,
        public readonly ?int $quantity,
        public readonly string $updatedAt,
    ) {}

    public static function fromItemStock(ItemStock $stock, MenuItem $item): self
    {
        return new self(
            $stock->game_id,
            $item->booth_id,
            $item->id,
            $stock->status->value,
            $stock->status->label(),
            $stock->quantity,
            $stock->updated_at->toIso8601String(),
        );
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->gameId}")];
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /** @return array<string, int|string|null> */
    public function broadcastWith(): array
    {
        return [
            'game_id' => $this->gameId,
            'booth_id' => $this->boothId,
            'menu_item_id' => $this->menuItemId,
            'status' => $this->status,
            'label' => $this->label,
            'quantity' => $this->quantity,
            'updated_at' => $this->updatedAt,
        ];
    }
}
