<?php

namespace App\Services;

use App\Enums\CongestionLevel;
use App\Events\BoothCongestionUpdated;
use App\Events\ItemStockUpdated;
use App\Enums\StockStatus;
use App\Exceptions\ServiceException;
use App\Models\Booth;
use App\Models\CongestionLog;
use App\Models\Game;
use App\Models\GameBooth;
use App\Models\ItemStock;
use App\Models\MenuItem;
use App\Models\StockLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * スタッフ・管理者による混雑（STAFF-02）と在庫（STAFF-03）の更新。
 * 誰が更新してよいかは呼び出し前に Policy で確認済みの前提（ここでは認可しない）。
 * 現在値の更新と履歴の追加は 1 つのトランザクションで行い、確定後に一覧のキャッシュを捨ててから、
 * 試合ごとの公開チャンネル（game.{gameId}）へ配信する。配信を最後にするのは、受け取った画面が一覧を取り直したときに
 * 最新の値が返るようにするため（キャッシュが先に捨てられている）。
 */
class BoothStatusService
{
    public function __construct(private readonly BoothService $booths) {}

    public function updateCongestion(Game $game, Booth $booth, User $by, CongestionLevel $level, ?int $waitMinutes): GameBooth
    {
        $gameBooth = $this->booths->gameBoothOrFail($game, $booth);

        DB::transaction(function () use ($gameBooth, $by, $level, $waitMinutes) {
            $gameBooth->update([
                'congestion_level' => $level,
                'wait_minutes' => $waitMinutes,
                'congestion_updated_at' => now(),
                'congestion_updated_by' => $by->id,
            ]);
            CongestionLog::create([
                'game_booth_id' => $gameBooth->id,
                'level' => $level,
                'wait_minutes' => $waitMinutes,
                'user_id' => $by->id,
            ]);
        });

        $this->booths->forgetList($game);
        $this->announce(BoothCongestionUpdated::fromGameBooth($gameBooth));

        return $gameBooth->setRelation('booth', $booth);
    }

    public function updateStock(Game $game, MenuItem $item, User $by, StockStatus $status, ?int $quantity): ItemStock
    {
        if (! GameBooth::query()->where('game_id', $game->id)->where('booth_id', $item->booth_id)->exists()) {
            throw ServiceException::notFound('この試合に出店していないブースのメニューです');
        }

        $stock = DB::transaction(function () use ($game, $item, $by, $status, $quantity) {
            $values = ['status' => $status, 'quantity' => $quantity, 'updated_by' => $by->id];
            // 2 人が同時に初めて登録しても一意制約（試合 × メニュー）で 500 にならないよう、createOrFirst で作る
            $stock = ItemStock::query()->createOrFirst(['game_id' => $game->id, 'menu_item_id' => $item->id], $values);
            if (! $stock->wasRecentlyCreated) {
                $stock->fill($values);
                // 値が同じでも「いま確認した」ことを最終更新に反映する（「最終更新 ○分前」が古いままにならないように）
                $stock->isDirty() ? $stock->save() : $stock->touch();
            }

            StockLog::create([
                'item_stock_id' => $stock->id,
                'status' => $status,
                'quantity' => $quantity,
                'user_id' => $by->id,
            ]);

            return $stock;
        });

        $this->booths->forgetList($game);
        $this->announce(ItemStockUpdated::fromItemStock($stock, $item));

        return $stock;
    }

    /**
     * リアルタイム配信。ここで失敗しても（Pusher の障害、キューの不調）、スタッフの更新自体は成功させる。
     * 更新は確定済みで、画面は再接続・再読み込み・定期的な取り直しで追いつくため。失敗は記録だけする。
     */
    private function announce(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
