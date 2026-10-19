<?php

namespace App\Services;

use App\Exceptions\ServiceException;
use App\Models\Booth;
use App\Models\Game;
use App\Models\GameBooth;
use App\Models\ItemStock;
use Illuminate\Support\Facades\DB;

/** 試合へのブースの割り当てと、営業中 / 閉店中（is_open）の切り替え（ADMIN-04）。誰が操作してよいかは、ルートの role:admin で確認済みの前提 */
class GameBoothAssignmentService
{
    public function __construct(private readonly BoothService $booths) {}

    /**
     * すべてのブースについて、この試合に割り当て済みかどうかを返す（割り当て画面は全ブースを一覧にして、チェックを付け外しする）。
     * `has_records` は混雑・在庫の記録があり、割り当てを外せないブース。
     *
     * @return array<int, array{booth: Booth, assigned: bool, is_open: bool, has_records: bool}>
     */
    public function list(Game $game): array
    {
        $assigned = $game->gameBooths()->get()->keyBy('booth_id');
        $withStock = $this->boothIdsWithStock($game);

        return Booth::query()->orderBy('name')->orderBy('id')->get()->map(function (Booth $booth) use ($assigned, $withStock) {
            /** @var GameBooth|null $gb */
            $gb = $assigned->get($booth->id);

            return [
                'booth' => $booth,
                'assigned' => $gb !== null,
                'is_open' => $gb?->is_open ?? true,
                'has_records' => $gb !== null && ($gb->congestion_updated_at !== null || isset($withStock[$booth->id])),
            ];
        })->all();
    }

    /**
     * この試合に出店するブースを、$items の内容にそろえる（足りないものは追加、$items に無いものは外す、is_open は更新）。
     * 混雑・在庫の記録があるブースは外せない（記録が消えてしまうため）。外したいときは is_open=false（閉店）にしてもらう。
     *
     * @param  array<int, array{booth_id: int, is_open?: bool}>  $items
     * @return array<int, array{booth: Booth, assigned: bool, is_open: bool, has_records: bool}>
     */
    public function sync(Game $game, array $items): array
    {
        $wanted = collect($items)->keyBy('booth_id');

        DB::transaction(function () use ($game, $wanted) {
            $current = $game->gameBooths()->with('booth')->get()->keyBy('booth_id');
            $withStock = $this->boothIdsWithStock($game);

            foreach ($current as $boothId => $gb) {
                if ($wanted->has($boothId)) {
                    continue;
                }
                if ($gb->congestion_updated_at !== null || isset($withStock[$boothId])) {
                    throw new ServiceException("「{$gb->booth->name}」は混雑・在庫の記録があるため、試合から外せません。営業中をオフ（閉店）にしてください", 422);
                }
                $gb->delete();
            }

            foreach ($wanted as $boothId => $item) {
                $isOpen = (bool) ($item['is_open'] ?? true);
                $gb = $current->get($boothId);
                if ($gb === null) {
                    $game->gameBooths()->create(['booth_id' => $boothId, 'is_open' => $isOpen]);
                } elseif ($gb->is_open !== $isOpen) {
                    $gb->update(['is_open' => $isOpen]);
                }
            }
        });

        $this->booths->forgetList($game);

        return $this->list($game);
    }

    /**
     * この試合で在庫の記録があるブースの id（キー）
     *
     * @return array<int, true>
     */
    private function boothIdsWithStock(Game $game): array
    {
        return ItemStock::query()
            ->join('menu_items', 'menu_items.id', '=', 'item_stocks.menu_item_id')
            ->where('item_stocks.game_id', $game->id)
            ->pluck('menu_items.booth_id')
            ->unique()
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }
}
