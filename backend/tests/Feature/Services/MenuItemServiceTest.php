<?php

namespace Tests\Feature\Services;

use App\Exceptions\ServiceException;
use App\Models\Booth;
use App\Models\Game;
use App\Models\GameBooth;
use App\Models\ItemStock;
use App\Models\MenuItem;
use App\Services\BoothService;
use App\Services\MenuItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuItemServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_ignores_fields_that_are_not_editable(): void
    {
        $booth = Booth::factory()->create();
        $other = Booth::factory()->create();

        $item = app(MenuItemService::class)->create($booth, [
            'name' => 'から揚げ', 'kind' => 'food', 'price' => 700, 'booth_id' => $other->id, 'id' => 999,
        ]);

        $this->assertSame($booth->id, $item->booth_id, '別のブースに作られない');
        $this->assertNotSame(999, $item->id);
        $this->assertTrue($item->is_active, '既定は販売中');
    }

    public function test_update_cannot_move_an_item_to_another_booth(): void
    {
        $item = MenuItem::factory()->create();
        $other = Booth::factory()->create();

        app(MenuItemService::class)->update($item, ['price' => 800, 'booth_id' => $other->id]);

        $item->refresh();
        $this->assertSame(800, $item->price);
        $this->assertNotSame($other->id, $item->booth_id);
    }

    public function test_delete_without_stock_records_removes_the_item(): void
    {
        $item = MenuItem::factory()->create();

        app(MenuItemService::class)->delete($item);

        $this->assertModelMissing($item);
    }

    public function test_delete_with_stock_records_is_rejected_to_keep_history(): void
    {
        $item = MenuItem::factory()->create();
        ItemStock::factory()->for($item, 'menuItem')->create();

        try {
            app(MenuItemService::class)->delete($item);
            $this->fail('ServiceException expected');
        } catch (ServiceException $e) {
            $this->assertSame(422, $e->status);
        }

        $this->assertModelExists($item);
    }

    public function test_menu_changes_invalidate_the_lists_of_every_game_the_booth_is_in(): void
    {
        $booth = Booth::factory()->create();
        $games = Game::factory()->count(2)->create();
        $booths = app(BoothService::class);
        foreach ($games as $game) {
            GameBooth::factory()->for($game)->for($booth)->create();
        }
        $item = MenuItem::factory()->create(['booth_id' => $booth->id]);
        foreach ($games as $game) {
            ItemStock::factory()->for($game)->for($item, 'menuItem')->create();
            $this->assertTrue($booths->listForGame($game)[0]['has_stock']);
        }

        app(MenuItemService::class)->update($item, ['is_active' => false]);

        foreach ($games as $game) {
            $this->assertFalse($booths->listForGame($game)[0]['has_stock'], '販売終了にしたら在庫ありの扱いから外れる');
        }
    }
}
