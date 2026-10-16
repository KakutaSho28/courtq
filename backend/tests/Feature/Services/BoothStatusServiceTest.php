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
use App\Models\StockLog;
use App\Models\User;
use App\Services\BoothService;
use App\Services\BoothStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BoothStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    private BoothStatusService $service;

    private Game $game;

    private GameBooth $gameBooth;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-16 19:30:00', 'Asia/Tokyo'));
        $this->service = app(BoothStatusService::class);
        $this->game = Game::factory()->today()->create();
        $this->gameBooth = GameBooth::factory()->for($this->game)->for(Booth::factory()->create())->create();
        $this->staff = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_update_congestion_sets_current_value_and_appends_a_log(): void
    {
        $result = $this->service->updateCongestion($this->game, $this->gameBooth->booth, $this->staff, CongestionLevel::Crowded, 25);

        $this->gameBooth->refresh();
        $this->assertSame(CongestionLevel::Crowded, $this->gameBooth->congestion_level);
        $this->assertSame(25, $this->gameBooth->wait_minutes);
        $this->assertSame($this->staff->id, $this->gameBooth->congestion_updated_by);
        $this->assertTrue($this->gameBooth->congestion_updated_at->eq(now()));
        $this->assertSame($this->gameBooth->id, $result->id);

        $log = CongestionLog::sole();
        $this->assertSame(CongestionLevel::Crowded, $log->level);
        $this->assertSame(25, $log->wait_minutes);
        $this->assertSame($this->staff->id, $log->user_id);
    }

    public function test_every_update_appends_a_log_row(): void
    {
        $booth = $this->gameBooth->booth;
        $this->service->updateCongestion($this->game, $booth, $this->staff, CongestionLevel::Empty, 0);
        $this->service->updateCongestion($this->game, $booth, $this->staff, CongestionLevel::Empty, 0);
        $this->service->updateCongestion($this->game, $booth, $this->staff, CongestionLevel::VeryCrowded, null);

        $this->assertSame(3, CongestionLog::count());
        $this->assertNull($this->gameBooth->refresh()->wait_minutes);
    }

    public function test_update_congestion_for_a_booth_not_in_the_game_is_404_and_writes_nothing(): void
    {
        try {
            $this->service->updateCongestion($this->game, Booth::factory()->create(), $this->staff, CongestionLevel::Empty, 0);
            $this->fail('ServiceException expected');
        } catch (ServiceException $e) {
            $this->assertSame(404, $e->status);
        }

        $this->assertSame(0, CongestionLog::count());
    }

    public function test_update_congestion_invalidates_the_list_cache(): void
    {
        $booths = app(BoothService::class);
        $booths->listForGame($this->game); // キャッシュに載せる

        $this->service->updateCongestion($this->game, $this->gameBooth->booth, $this->staff, CongestionLevel::VeryCrowded, 40);

        $this->assertSame(4, $booths->listForGame($this->game)[0]['congestion_level']);
    }

    private function item(?Booth $booth = null): MenuItem
    {
        return MenuItem::factory()->create(['booth_id' => ($booth ?? $this->gameBooth->booth)->id]);
    }

    public function test_update_stock_creates_the_stock_row_and_a_log(): void
    {
        $item = $this->item();

        $stock = $this->service->updateStock($this->game, $item, $this->staff, StockStatus::Low, 5);

        $this->assertSame(StockStatus::Low, $stock->status);
        $this->assertSame(5, $stock->quantity);
        $this->assertSame($this->staff->id, $stock->updated_by);
        $this->assertSame(1, ItemStock::count());
        $log = StockLog::sole();
        $this->assertSame($stock->id, $log->item_stock_id);
        $this->assertSame(StockStatus::Low, $log->status);
        $this->assertSame($this->staff->id, $log->user_id);
    }

    public function test_update_stock_updates_the_same_row_for_the_same_game_and_item(): void
    {
        $item = $this->item();
        $this->service->updateStock($this->game, $item, $this->staff, StockStatus::InStock, null);
        $this->service->updateStock($this->game, $item, $this->staff, StockStatus::SoldOut, 0);

        $this->assertSame(1, ItemStock::count());
        $this->assertSame(StockStatus::SoldOut, ItemStock::sole()->status);
        $this->assertSame(2, StockLog::count());
    }

    public function test_resending_the_same_stock_still_refreshes_updated_at(): void
    {
        $item = $this->item();
        $this->service->updateStock($this->game, $item, $this->staff, StockStatus::InStock, null);

        Carbon::setTestNow(now()->addMinutes(20));
        $stock = $this->service->updateStock($this->game, $item, $this->staff, StockStatus::InStock, null);

        $this->assertTrue($stock->refresh()->updated_at->eq(now()), '同じ値でも「いま確認した」ことが最終更新に反映される');
        $this->assertSame(2, StockLog::count());
    }

    public function test_update_stock_for_a_booth_not_in_the_game_is_404_and_writes_nothing(): void
    {
        $item = $this->item(Booth::factory()->create());

        try {
            $this->service->updateStock($this->game, $item, $this->staff, StockStatus::InStock, null);
            $this->fail('ServiceException expected');
        } catch (ServiceException $e) {
            $this->assertSame(404, $e->status);
        }

        $this->assertSame(0, ItemStock::count());
        $this->assertSame(0, StockLog::count());
    }

    public function test_update_stock_invalidates_the_list_cache(): void
    {
        $item = $this->item();
        $booths = app(BoothService::class);
        $this->assertFalse($booths->listForGame($this->game)[0]['has_stock']);

        $this->service->updateStock($this->game, $item, $this->staff, StockStatus::InStock, null);

        $this->assertTrue($booths->listForGame($this->game)[0]['has_stock']);
    }

    /** 別のリクエストが先に在庫の行を作っていても（同時に初めて登録したとき）、一意制約で失敗せずその行を更新する */
    public function test_update_stock_uses_the_row_created_concurrently_by_someone_else(): void
    {
        $item = $this->item();
        $existing = ItemStock::factory()->for($this->game)->for($item, 'menuItem')->status(StockStatus::InStock)->create();

        $stock = $this->service->updateStock($this->game, $item, $this->staff, StockStatus::Low, 2);

        $this->assertSame($existing->id, $stock->id);
        $this->assertSame(1, ItemStock::count());
        $this->assertSame(StockStatus::Low, $existing->refresh()->status);
    }
}
