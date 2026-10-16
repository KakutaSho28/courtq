<?php

namespace Tests\Feature;

use App\Enums\BoothCategory;
use App\Enums\CongestionLevel;
use App\Enums\GameStatus;
use App\Enums\StockStatus;
use App\Enums\UserRole;
use App\Models\Booth;
use App\Models\CongestionLog;
use App\Models\Game;
use App\Models\GameBooth;
use App\Models\ItemStock;
use App\Models\MenuItem;
use App\Models\StaffInvitation;
use App\Models\StockLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** マイグレーション・モデル・Factory が仕様どおりに噛み合っていること */
class DataModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_have_role_and_provider_columns_with_safe_defaults(): void
    {
        $this->assertTrue(Schema::hasColumns('users', ['role', 'provider', 'provider_user_id']));

        $user = User::factory()->create();

        $this->assertSame(UserRole::User, $user->fresh()->role);
        $this->assertNull($user->provider);
        $this->assertNull($user->provider_user_id);
    }

    public function test_role_cannot_be_mass_assigned(): void
    {
        $user = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'password123', 'role' => 'admin', 'provider' => 'x']);

        $this->assertSame(UserRole::User, $user->fresh()->role);
        $this->assertNull($user->fresh()->provider);
    }

    public function test_factory_states_and_helpers(): void
    {
        $this->assertTrue(User::factory()->admin()->create()->isAdmin());
        $this->assertTrue(User::factory()->staff()->create()->isStaff());
        $this->assertFalse(User::factory()->create()->isStaff());
    }

    public function test_every_table_in_the_spec_exists(): void
    {
        foreach (['venues', 'games', 'booths', 'game_booths', 'menu_items', 'item_stocks', 'booth_staff', 'staff_invitations', 'congestion_logs', 'stock_logs', 'favorites', 'cache', 'jobs'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} がありません");
        }
    }

    public function test_game_booth_is_unique_per_game_and_booth(): void
    {
        $gameBooth = GameBooth::factory()->create();

        $this->expectException(QueryException::class);
        GameBooth::factory()->create(['game_id' => $gameBooth->game_id, 'booth_id' => $gameBooth->booth_id]);
    }

    public function test_item_stock_is_unique_per_game_and_item(): void
    {
        $stock = ItemStock::factory()->create();

        $this->expectException(QueryException::class);
        ItemStock::factory()->create(['game_id' => $stock->game_id, 'menu_item_id' => $stock->menu_item_id]);
    }

    public function test_booth_staff_and_favorites_are_unique_pairs(): void
    {
        $booth = Booth::factory()->create();
        $user = User::factory()->staff()->create();
        $user->booths()->attach($booth);
        $user->favoriteBooths()->attach($booth);

        $this->assertTrue($booth->staff->contains($user));
        $this->assertTrue($booth->favoritedBy->contains($user));

        try {
            $user->booths()->attach($booth);
            $this->fail('booth_staff の同じ組み合わせを二重に登録できてしまいます');
        } catch (QueryException) {
            $this->assertSame(1, $user->booths()->count());
        }
        $this->expectException(QueryException::class);
        $user->favoriteBooths()->attach($booth);
    }

    public function test_invitation_code_is_unique(): void
    {
        StaffInvitation::factory()->create(['code' => 'ABCDEFGHIJ']);

        $this->expectException(QueryException::class);
        StaffInvitation::factory()->create(['code' => 'ABCDEFGHIJ']);
    }

    public function test_invitation_usable_scope_excludes_expired_and_used(): void
    {
        $usable = StaffInvitation::factory()->create();
        StaffInvitation::factory()->expired()->create();
        StaffInvitation::factory()->used()->create();

        $this->assertSame([$usable->id], StaffInvitation::usable()->pluck('id')->all());
        $this->assertTrue($usable->isUsable());
    }

    public function test_enum_casts_and_labels(): void
    {
        $gameBooth = GameBooth::factory()->withCongestion(CongestionLevel::VeryCrowded, 30)->create();
        $stock = ItemStock::factory()->status(StockStatus::Low, 5)->create();

        $this->assertSame(CongestionLevel::VeryCrowded, $gameBooth->fresh()->congestion_level);
        $this->assertSame('非常に混雑', $gameBooth->congestion_level->label());
        $this->assertSame(StockStatus::Low, $stock->fresh()->status);
        $this->assertSame('残りわずか', $stock->status->label());
        $this->assertSame([1, 2, 3, 4], CongestionLevel::values());
        $this->assertSame(BoothCategory::Both, Booth::factory()->both()->create()->fresh()->category);
        $this->assertSame(GameStatus::Live, Game::factory()->today()->create()->fresh()->status);
    }

    public function test_logs_are_append_only_with_created_at_and_updater(): void
    {
        $user = User::factory()->staff()->create();
        $gameBooth = GameBooth::factory()->create();
        $stock = ItemStock::factory()->create();

        $congestionLog = CongestionLog::create(['game_booth_id' => $gameBooth->id, 'level' => 3, 'wait_minutes' => 12, 'user_id' => $user->id]);
        $stockLog = StockLog::create(['item_stock_id' => $stock->id, 'status' => 'sold_out', 'quantity' => 0, 'user_id' => $user->id]);

        $this->assertNotNull($congestionLog->fresh()->created_at);
        $this->assertSame($user->id, $congestionLog->fresh()->user_id);
        $this->assertSame(StockStatus::SoldOut, $stockLog->fresh()->status);
        $this->assertFalse(Schema::hasColumn('congestion_logs', 'updated_at'));
        $this->assertFalse(Schema::hasColumn('stock_logs', 'updated_at'));
    }

    public function test_deleting_a_game_removes_its_booth_rows_and_stocks_but_not_booths_or_menu(): void
    {
        $game = Game::factory()->create();
        $booth = Booth::factory()->create();
        $item = MenuItem::factory()->create(['booth_id' => $booth->id]);
        GameBooth::factory()->create(['game_id' => $game->id, 'booth_id' => $booth->id]);
        ItemStock::factory()->create(['game_id' => $game->id, 'menu_item_id' => $item->id]);

        $game->delete();

        $this->assertSame(0, GameBooth::count());
        $this->assertSame(0, ItemStock::count());
        $this->assertSame(1, Booth::count());
        $this->assertSame(1, MenuItem::count());
    }

    public function test_deleting_a_user_keeps_logs_and_history(): void
    {
        $user = User::factory()->staff()->create();
        $gameBooth = GameBooth::factory()->create(['congestion_updated_by' => $user->id]);
        $log = CongestionLog::create(['game_booth_id' => $gameBooth->id, 'level' => 1, 'user_id' => $user->id]);

        $user->delete();

        $this->assertNull($gameBooth->fresh()->congestion_updated_by);
        $this->assertNull($log->fresh()->user_id);
    }

    public function test_game_on_date_scope_uses_the_app_timezone(): void
    {
        $this->assertSame('Asia/Tokyo', config('app.timezone'));
        $todayGame = Game::factory()->create(['tipoff_at' => now()->setTime(19, 5)]);
        Game::factory()->create(['tipoff_at' => now()->addDay()->setTime(14, 5)]);

        $this->assertSame([$todayGame->id], Game::onDate(now())->pluck('id')->all());
    }
}
