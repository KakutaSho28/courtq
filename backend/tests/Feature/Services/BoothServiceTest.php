<?php

namespace Tests\Feature\Services;

use App\Enums\CongestionLevel;
use App\Enums\StockStatus;
use App\Exceptions\ServiceException;
use App\Models\Booth;
use App\Models\CongestionLog;
use App\Models\Game;
use App\Models\GameBooth;
use App\Models\ItemStock;
use App\Models\MenuItem;
use App\Models\User;
use App\Services\BoothService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BoothServiceTest extends TestCase
{
    use RefreshDatabase;

    private BoothService $service;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BoothService::class);
        $this->game = Game::factory()->create();
    }

    private function booth(string $name, ?CongestionLevel $level = null, ?int $wait = null, bool $open = true, string $category = 'food'): GameBooth
    {
        $booth = Booth::factory()->create(['name' => $name, 'category' => $category]);
        $factory = GameBooth::factory()->for($this->game)->for($booth);
        if ($level !== null) {
            $factory = $factory->withCongestion($level, $wait);
        }
        if (! $open) {
            $factory = $factory->closed();
        }

        return $factory->create();
    }

    /** @return array<int, string> */
    private function names(array $items): array
    {
        return array_column($items, 'name');
    }

    public function test_list_only_includes_booths_of_the_game(): void
    {
        $this->booth('この試合のブース');
        GameBooth::factory()->create(); // 別の試合

        $this->assertSame(['この試合のブース'], $this->names($this->service->listForGame($this->game)));
    }

    public function test_list_item_has_the_light_payload_fields(): void
    {
        $this->booth('A', CongestionLevel::Crowded, 20);

        $item = $this->service->listForGame($this->game)[0];

        $this->assertEqualsCanonicalizing([
            'game_booth_id', 'booth_id', 'name', 'category', 'location_label', 'is_open',
            'congestion_level', 'wait_minutes', 'congestion_updated_at', 'has_stock', 'is_favorite',
        ], array_keys($item));
        $this->assertSame(3, $item['congestion_level']);
        $this->assertSame(20, $item['wait_minutes']);
    }

    public function test_sort_by_name_puts_closed_booths_last(): void
    {
        $this->booth('C');
        $this->booth('A', open: false);
        $this->booth('B');

        $this->assertSame(['B', 'C', 'A'], $this->names($this->service->listForGame($this->game)));
    }

    public function test_sort_by_congestion_is_emptiest_first_with_unknown_and_closed_last(): void
    {
        $this->booth('未更新');
        $this->booth('混雑', CongestionLevel::Crowded, 25);
        $this->booth('閉店', CongestionLevel::Empty, 0, open: false);
        $this->booth('空き', CongestionLevel::Empty, 0);
        $this->booth('やや', CongestionLevel::Slightly, 8);

        $names = $this->names($this->service->listForGame($this->game, sort: BoothService::SORT_CONGESTION));

        $this->assertSame(['空き', 'やや', '混雑', '未更新', '閉店'], $names);
    }

    public function test_sort_by_wait_is_shortest_first_with_unknown_last(): void
    {
        $this->booth('長い', CongestionLevel::VeryCrowded, 40);
        $this->booth('不明');
        $this->booth('短い', CongestionLevel::Slightly, 5);

        $names = $this->names($this->service->listForGame($this->game, sort: BoothService::SORT_WAIT));

        $this->assertSame(['短い', '長い', '不明'], $names);
    }

    public function test_category_filter_includes_both_booths_for_food_and_merch(): void
    {
        $this->booth('飲食', category: 'food');
        $this->booth('物販', category: 'merch');
        $this->booth('兼営', category: 'both');

        $this->assertEqualsCanonicalizing(['飲食', '兼営'], $this->names($this->service->listForGame($this->game, category: 'food')));
        $this->assertEqualsCanonicalizing(['物販', '兼営'], $this->names($this->service->listForGame($this->game, category: 'merch')));
        $this->assertSame(['兼営'], $this->names($this->service->listForGame($this->game, category: 'both')));
    }

    public function test_in_stock_filter_requires_an_active_item_that_is_not_sold_out(): void
    {
        $available = $this->booth('在庫あり');
        $soldOut = $this->booth('売り切れ');
        $inactiveOnly = $this->booth('販売終了のみ');
        $noStock = $this->booth('在庫未登録');
        $low = $this->booth('残りわずか');

        $this->stock($available, StockStatus::InStock);
        $this->stock($soldOut, StockStatus::SoldOut);
        $this->stock($inactiveOnly, StockStatus::InStock, active: false);
        $this->stock($low, StockStatus::Low);
        // 売り切れの商品と在庫ありの商品が混ざっていれば「在庫あり」
        $this->stock($soldOut, StockStatus::InStock);
        unset($noStock);

        $names = $this->names($this->service->listForGame($this->game, inStockOnly: true));

        $this->assertEqualsCanonicalizing(['在庫あり', '売り切れ', '残りわずか'], $names);
    }

    private function stock(GameBooth $gameBooth, StockStatus $status, bool $active = true): void
    {
        $item = MenuItem::factory()->create(['booth_id' => $gameBooth->booth_id, 'is_active' => $active]);
        ItemStock::factory()->for($this->game)->for($item, 'menuItem')->status($status)->create();
    }

    public function test_favorites_filter_and_flag_are_per_user(): void
    {
        $mine = $this->booth('お気に入り');
        $this->booth('その他');
        $user = User::factory()->create();
        $user->favoriteBooths()->attach($mine->booth_id);

        $all = $this->service->listForGame($this->game, user: $user);
        $favorites = $this->service->listForGame($this->game, favoritesOnly: true, user: $user);
        $anonymous = $this->service->listForGame($this->game);

        $this->assertEquals(['その他' => false, 'お気に入り' => true], array_column($all, 'is_favorite', 'name'));
        $this->assertSame(['お気に入り'], $this->names($favorites));
        $this->assertSame([false, false], array_column($anonymous, 'is_favorite'));
    }

    public function test_list_is_cached_until_forgotten(): void
    {
        $gameBooth = $this->booth('A', CongestionLevel::Empty, 0);
        $this->service->listForGame($this->game); // キャッシュに載る

        $gameBooth->update(['congestion_level' => CongestionLevel::VeryCrowded, 'wait_minutes' => 60]);

        $this->assertSame(1, $this->service->listForGame($this->game)[0]['congestion_level'], 'キャッシュ中は古い値');

        $this->service->forgetList($this->game);

        $this->assertSame(4, $this->service->listForGame($this->game)[0]['congestion_level'], '破棄後は最新の値');
    }

    public function test_cache_is_per_game(): void
    {
        $this->booth('A');
        $other = Game::factory()->create();
        GameBooth::factory()->for($other)->for(Booth::factory()->create(['name' => 'Z']))->create();
        $this->service->listForGame($this->game);

        $this->assertSame(['Z'], $this->names($this->service->listForGame($other)));
    }

    public function test_user_specific_favorites_are_not_leaked_through_the_cache(): void
    {
        $gameBooth = $this->booth('A');
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $alice->favoriteBooths()->attach($gameBooth->booth_id);

        $this->service->listForGame($this->game, user: $alice); // alice のあとで bob が見る

        $this->assertFalse($this->service->listForGame($this->game, user: $bob)[0]['is_favorite']);
    }

    public function test_detail_returns_active_items_with_their_stock_for_this_game(): void
    {
        $gameBooth = $this->booth('A', CongestionLevel::Slightly, 5);
        $food = MenuItem::factory()->create(['booth_id' => $gameBooth->booth_id, 'name' => 'から揚げ']);
        $goods = MenuItem::factory()->merch()->create(['booth_id' => $gameBooth->booth_id, 'name' => 'タオル']);
        MenuItem::factory()->inactive()->create(['booth_id' => $gameBooth->booth_id]);
        ItemStock::factory()->for($this->game)->for($food, 'menuItem')->status(StockStatus::Low, 3)->create();
        ItemStock::factory()->for(Game::factory()->create())->for($goods, 'menuItem')->status(StockStatus::SoldOut)->create(); // 別の試合の在庫

        $detail = $this->service->detail($this->game, $gameBooth->booth);

        $this->assertSame($gameBooth->id, $detail['game_booth']->id);
        $this->assertSame(['から揚げ', 'タオル'], $detail['items']->pluck('name')->all());
        $this->assertSame(StockStatus::Low, $detail['items'][0]->stock->status);
        $this->assertNull($detail['items'][1]->stock, '別の試合の在庫は混ざらない');
        $this->assertFalse($detail['is_favorite']);
    }

    public function test_detail_reports_favorite_for_the_user(): void
    {
        $gameBooth = $this->booth('A');
        $user = User::factory()->create();
        $user->favoriteBooths()->attach($gameBooth->booth_id);

        $this->assertTrue($this->service->detail($this->game, $gameBooth->booth, $user)['is_favorite']);
    }

    public function test_detail_of_a_booth_not_in_the_game_is_404(): void
    {
        $other = Booth::factory()->create();

        try {
            $this->service->detail($this->game, $other);
            $this->fail('ServiceException expected');
        } catch (ServiceException $e) {
            $this->assertSame(404, $e->status);
        }
    }

    public function test_congestion_history_is_oldest_first_and_limited_to_the_latest_200(): void
    {
        $gameBooth = $this->booth('A');
        for ($i = 1; $i <= 205; $i++) {
            // 新しいものから順に作って、id の順と時刻の順が逆でも「時刻の古い順」で返ることを確かめる
            (new CongestionLog)->forceFill(['game_booth_id' => $gameBooth->id, 'level' => 1 + ($i % 4), 'wait_minutes' => 206 - $i, 'user_id' => null, 'created_at' => now()->subMinutes($i)])->save();
        }

        $history = $this->service->congestionHistory($this->game, $gameBooth->booth);

        $this->assertCount(200, $history);
        // i = 205 が最も古い（205 分前）。直近 200 件は i = 1..200 で、先頭が i = 200（wait = 6）、末尾が i = 1（wait = 205）
        $this->assertSame(6, $history->first()->wait_minutes);
        $this->assertSame(205, $history->last()->wait_minutes);
        $this->assertTrue($history->first()->created_at->lt($history->last()->created_at));
    }

    public function test_congestion_history_of_a_booth_not_in_the_game_is_404(): void
    {
        $this->expectException(ServiceException::class);

        $this->service->congestionHistory($this->game, Booth::factory()->create());
    }
}
