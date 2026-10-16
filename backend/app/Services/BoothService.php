<?php

namespace App\Services;

use App\Enums\BoothCategory;
use App\Exceptions\ServiceException;
use App\Models\Booth;
use App\Models\CongestionLog;
use App\Models\Game;
use App\Models\GameBooth;
use App\Models\ItemStock;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * ブースの閲覧（一覧・詳細・混雑の履歴）と、一覧キャッシュの破棄。書き込みは BoothStatusService / MenuItemService。
 */
class BoothService
{
    public const SORT_NAME = 'name';

    public const SORT_CONGESTION = 'congestion';

    public const SORT_WAIT = 'wait';

    /** 混雑の履歴として返す最大件数（直近の分。グラフに十分で、応答は軽く保つ） */
    private const HISTORY_LIMIT = 200;

    /**
     * 試合のブース一覧（BOOTH-01）。
     *
     * 全員に共通の部分（ブース・混雑・在庫の有無）だけを短時間キャッシュし、
     * 絞り込み・並び替え・お気に入りはキャッシュの外で行う（お気に入りはユーザーごとに違うため）。
     *
     * @param  string|null  $category  food / merch は兼営（both）のブースも含む。both は兼営のブースだけ
     * @param  string  $sort  name（既定）/ congestion（空いている順）/ wait（待ち時間の短い順）
     * @return array<int, array<string, mixed>>
     */
    public function listForGame(
        Game $game,
        ?string $category = null,
        string $sort = self::SORT_NAME,
        bool $inStockOnly = false,
        bool $favoritesOnly = false,
        ?User $user = null,
    ): array {
        $favoriteIds = $user?->favoriteBooths()->pluck('booths.id')->all() ?? [];

        $items = array_values(array_filter(
            $this->snapshot($game),
            fn (array $item) => $this->matches($item, $category, $inStockOnly, $favoritesOnly, $favoriteIds),
        ));

        usort($items, fn (array $a, array $b) => $this->compare($a, $b, $sort));

        return array_map(fn (array $item) => $item + ['is_favorite' => in_array($item['booth_id'], $favoriteIds, true)], $items);
    }

    /** 混雑・在庫を更新したら呼ぶ（一覧のキャッシュを捨てる） */
    public function forgetList(Game|int $game): void
    {
        Cache::forget($this->cacheKey($game instanceof Game ? $game->id : $game));
    }

    /** メニューを変えたら呼ぶ。そのブースが出店しているすべての試合の一覧キャッシュを捨てる（在庫の有無が変わりうるため） */
    public function forgetListsForBooth(Booth $booth): void
    {
        foreach (GameBooth::query()->where('booth_id', $booth->id)->pluck('game_id') as $gameId) {
            $this->forgetList($gameId);
        }
    }

    /**
     * ブースの詳細（BOOTH-02）: 混雑・メニュー・在庫（メニューは販売中のものだけ）。
     *
     * @return array{game_booth: GameBooth, items: Collection<int, MenuItem>, is_favorite: bool}
     */
    public function detail(Game $game, Booth $booth, ?User $user = null): array
    {
        $gameBooth = $this->gameBoothOrFail($game, $booth);
        $gameBooth->setRelation('booth', $booth);

        $items = $booth->menuItems()->where('is_active', true)->orderBy('kind')->orderBy('id')->get();
        $stocks = ItemStock::query()
            ->where('game_id', $game->id)
            ->whereIn('menu_item_id', $items->modelKeys())
            ->get()
            ->keyBy('menu_item_id');
        foreach ($items as $item) {
            $item->setRelation('stock', $stocks->get($item->id));
        }

        return [
            'game_booth' => $gameBooth,
            'items' => $items,
            'is_favorite' => $user !== null && $user->favoriteBooths()->whereKey($booth->id)->exists(),
        ];
    }

    /**
     * 混雑の履歴（BOOTH-03）。古い順で、直近の最大 200 件。
     *
     * @return Collection<int, CongestionLog>
     */
    public function congestionHistory(Game $game, Booth $booth): Collection
    {
        $gameBooth = $this->gameBoothOrFail($game, $booth);

        return CongestionLog::query()
            ->where('game_booth_id', $gameBooth->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->reverse()
            ->values();
    }

    /** その試合に出店しているブースの行（game_booths）。出店していなければ 404 */
    public function gameBoothOrFail(Game $game, Booth $booth): GameBooth
    {
        $gameBooth = GameBooth::query()->where('game_id', $game->id)->where('booth_id', $booth->id)->first();

        if ($gameBooth === null) {
            throw ServiceException::notFound('この試合に出店していないブースです');
        }

        return $gameBooth;
    }

    /**
     * 全員に共通の一覧データ（短時間キャッシュ）。値は配列・文字列・数値だけにして、どのキャッシュドライバでも保存できるようにする。
     * 時間とともに変わる is_stale はここに含めず、Resource が毎回計算する。
     *
     * @return array<int, array<string, mixed>>
     */
    private function snapshot(Game $game): array
    {
        return Cache::remember(
            $this->cacheKey($game->id),
            now()->addSeconds((int) config('courtq.list_cache_seconds')),
            fn () => $this->buildSnapshot($game),
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function buildSnapshot(Game $game): array
    {
        // 販売中のメニューのうち、売り切れでない在庫が 1 つでもあるブース
        $boothIdsWithStock = ItemStock::query()
            ->join('menu_items', 'menu_items.id', '=', 'item_stocks.menu_item_id')
            ->where('item_stocks.game_id', $game->id)
            ->where('menu_items.is_active', true)
            ->where('item_stocks.status', '!=', 'sold_out')
            ->pluck('menu_items.booth_id')
            ->unique()
            ->flip();

        return GameBooth::query()
            ->with('booth')
            ->where('game_id', $game->id)
            ->get()
            ->map(fn (GameBooth $gb) => [
                'game_booth_id' => $gb->id,
                'booth_id' => $gb->booth_id,
                'name' => $gb->booth->name,
                'category' => $gb->booth->category->value,
                'location_label' => $gb->booth->location_label,
                'is_open' => $gb->is_open,
                'congestion_level' => $gb->congestion_level?->value,
                'wait_minutes' => $gb->wait_minutes,
                'congestion_updated_at' => $gb->congestion_updated_at?->toIso8601String(),
                'has_stock' => $boothIdsWithStock->has($gb->booth_id),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<int, int>  $favoriteIds
     */
    private function matches(array $item, ?string $category, bool $inStockOnly, bool $favoritesOnly, array $favoriteIds): bool
    {
        if ($category !== null && ! $this->categoryMatches($item['category'], $category)) {
            return false;
        }
        if ($inStockOnly && ! $item['has_stock']) {
            return false;
        }
        if ($favoritesOnly && ! in_array($item['booth_id'], $favoriteIds, true)) {
            return false;
        }

        return true;
    }

    private function categoryMatches(string $boothCategory, string $wanted): bool
    {
        // 飲食を探す人には、飲食と物販の兼営ブースも見えるようにする
        return $boothCategory === $wanted
            || ($boothCategory === BoothCategory::Both->value && $wanted !== BoothCategory::Both->value);
    }

    /**
     * 並び順。どの並び方でも「営業中が先、閉店中は最後」「同じなら名前順」。
     * 混雑・待ち時間が未入力（null）のブースは、入力済みのブースより後ろにする。
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function compare(array $a, array $b, string $sort): int
    {
        $key = fn (array $item): array => match ($sort) {
            self::SORT_CONGESTION => [! $item['is_open'], $item['congestion_level'] ?? PHP_INT_MAX, $item['wait_minutes'] ?? PHP_INT_MAX, $item['name']],
            self::SORT_WAIT => [! $item['is_open'], $item['wait_minutes'] ?? PHP_INT_MAX, $item['congestion_level'] ?? PHP_INT_MAX, $item['name']],
            default => [! $item['is_open'], $item['name']],
        };

        return $key($a) <=> $key($b);
    }

    private function cacheKey(int $gameId): string
    {
        return "courtq:game:{$gameId}:booth-list";
    }
}
