<?php

namespace Tests\Feature\Services;

use App\Exceptions\ServiceException;
use App\Models\Booth;
use App\Models\Game;
use App\Models\GameBooth;
use App\Models\ItemStock;
use App\Models\MenuItem;
use App\Models\User;
use App\Services\GameBoothAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameBoothAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = Game::factory()->create();
    }

    private function service(): GameBoothAssignmentService
    {
        return app(GameBoothAssignmentService::class);
    }

    public function test_list_shows_every_booth_with_its_assignment(): void
    {
        $a = Booth::factory()->create(['name' => 'A']);
        $b = Booth::factory()->create(['name' => 'B']);
        GameBooth::factory()->for($this->game)->for($b)->closed()->create();

        $list = $this->service()->list($this->game);

        $this->assertSame([$a->id, $b->id], array_map(fn ($r) => $r['booth']->id, $list));
        $this->assertFalse($list[0]['assigned']);
        $this->assertTrue($list[1]['assigned']);
        $this->assertFalse($list[1]['is_open']);
    }

    public function test_sync_adds_updates_and_removes_to_match_the_request(): void
    {
        [$keep, $drop, $new] = Booth::factory()->count(3)->create()->all();
        GameBooth::factory()->for($this->game)->for($keep)->create(['is_open' => true]);
        GameBooth::factory()->for($this->game)->for($drop)->create();

        $result = $this->service()->sync($this->game, [
            ['booth_id' => $keep->id, 'is_open' => false],
            ['booth_id' => $new->id],
        ]);

        $rows = GameBooth::where('game_id', $this->game->id)->get()->keyBy('booth_id');
        $this->assertSame([$keep->id, $new->id], $rows->keys()->sort()->values()->all());
        $this->assertFalse($rows[$keep->id]->is_open, 'is_open が更新される');
        $this->assertTrue($rows[$new->id]->is_open, '省略した is_open は営業中');
        $this->assertCount(3, $result, '結果は全ブースの一覧');
    }

    public function test_sync_does_not_touch_other_games(): void
    {
        $booth = Booth::factory()->create();
        $other = Game::factory()->create();
        GameBooth::factory()->for($other)->for($booth)->create();

        $this->service()->sync($this->game, []);

        $this->assertSame(1, GameBooth::where('game_id', $other->id)->count());
    }

    public function test_a_booth_with_congestion_records_cannot_be_removed(): void
    {
        $booth = Booth::factory()->create(['name' => 'から揚げ']);
        GameBooth::factory()->for($this->game)->for($booth)->withCongestion()->create();

        try {
            $this->service()->sync($this->game, []);
            $this->fail('ServiceException が投げられませんでした');
        } catch (ServiceException $e) {
            $this->assertStringContainsString('から揚げ', $e->getMessage());
            $this->assertStringContainsString('閉店', $e->getMessage());
        }
        $this->assertSame(1, GameBooth::count());
    }

    public function test_a_booth_with_stock_records_cannot_be_removed_but_can_be_closed(): void
    {
        $booth = Booth::factory()->create();
        GameBooth::factory()->for($this->game)->for($booth)->create();
        $item = MenuItem::factory()->create(['booth_id' => $booth->id]);
        ItemStock::factory()->create(['game_id' => $this->game->id, 'menu_item_id' => $item->id]);

        $list = $this->service()->list($this->game);
        $this->assertTrue($list[0]['has_records']);

        try {
            $this->service()->sync($this->game, []);
            $this->fail('ServiceException が投げられませんでした');
        } catch (ServiceException) {
        }

        $this->service()->sync($this->game, [['booth_id' => $booth->id, 'is_open' => false]]);
        $this->assertFalse(GameBooth::sole()->is_open);
    }

    public function test_a_refused_removal_changes_nothing_at_all(): void
    {
        [$ok, $recorded, $added] = Booth::factory()->count(3)->create()->all();
        GameBooth::factory()->for($this->game)->for($ok)->create();
        GameBooth::factory()->for($this->game)->for($recorded)->withCongestion()->create();

        try {
            $this->service()->sync($this->game, [['booth_id' => $added->id]]);
        } catch (ServiceException) {
        }

        $this->assertEqualsCanonicalizing([$ok->id, $recorded->id], GameBooth::pluck('booth_id')->all(), '途中まで進んだ変更も戻る');
    }

    public function test_sync_clears_the_booth_list_cache_so_visitors_see_the_change(): void
    {
        $booth = Booth::factory()->create();
        GameBooth::factory()->for($this->game)->for($booth)->create();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/games/{$this->game->id}/booths")->assertOk();
        $this->assertTrue(Cache::has("courtq:game:{$this->game->id}:booth-list"));

        $this->service()->sync($this->game, [['booth_id' => $booth->id, 'is_open' => false]]);

        $this->assertFalse(Cache::has("courtq:game:{$this->game->id}:booth-list"));
    }
}
