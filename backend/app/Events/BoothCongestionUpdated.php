<?php

namespace App\Events;

use App\Models\GameBooth;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ブースの混雑が更新された。試合ごとの公開チャンネル `game.{gameId}` に、キュー経由で配信する。
 *
 * ペイロードは小さく保つ（Pusher は約 10KB が上限。ここは 150 バイト程度）。モデルではなく数値と文字列だけを持つので、
 * キューに積んでから worker が送るまでの間に値が変わっても、「更新した時点の値」が配信される。
 */
class BoothCongestionUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public const NAME = 'booth.congestion.updated';

    public function __construct(
        public readonly int $gameId,
        public readonly int $boothId,
        public readonly int $gameBoothId,
        public readonly int $level,
        public readonly string $label,
        public readonly ?int $waitMinutes,
        public readonly string $updatedAt,
    ) {}

    public static function fromGameBooth(GameBooth $gameBooth): self
    {
        return new self(
            $gameBooth->game_id,
            $gameBooth->booth_id,
            $gameBooth->id,
            $gameBooth->congestion_level->value,
            $gameBooth->congestion_level->label(),
            $gameBooth->wait_minutes,
            $gameBooth->congestion_updated_at->toIso8601String(),
        );
    }

    /** @return array<int, Channel> 公開チャンネル（認証なし）。閲覧者ごとの認証の負荷を避けるため */
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
            'game_booth_id' => $this->gameBoothId,
            'level' => $this->level,
            'label' => $this->label,
            'wait_minutes' => $this->waitMinutes,
            'updated_at' => $this->updatedAt,
        ];
    }
}
