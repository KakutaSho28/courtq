<?php

namespace Tests\Feature;

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
use App\Models\StockLog;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_the_documented_demo_data(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, Venue::count());
        $this->assertSame(2, Game::count());
        $this->assertSame(8, Booth::count());
        $this->assertSame(16, GameBooth::count()); // 2 試合 × 8 ブース
        $this->assertGreaterThanOrEqual(8, MenuItem::count());

        $this->assertSame(1, User::where('role', UserRole::Admin)->count());
        $this->assertSame(2, User::where('role', UserRole::Staff)->count());
        $this->assertSame(2, User::where('role', UserRole::User)->count());
    }

    public function test_has_a_game_today_and_it_is_live(): void
    {
        $this->seed(DatabaseSeeder::class);

        $today = Game::onDate(now())->get();

        $this->assertCount(1, $today);
        $this->assertSame(GameStatus::Live, $today->first()->status);
    }

    public function test_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $counts = $this->counts();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($counts, $this->counts());
    }

    public function test_demo_accounts_can_log_in_with_the_documented_password(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (['admin@example.com', 'staff1@example.com', 'staff2@example.com', 'user1@example.com', 'user2@example.com'] as $email) {
            $this->postJson('/api/auth/login', ['email' => $email, 'password' => config('demo.password')])->assertOk();
        }
    }

    public function test_does_not_overwrite_a_changed_password(): void
    {
        $this->seed(DatabaseSeeder::class);
        User::where('email', 'admin@example.com')->update(['password' => Hash::make('changed-password')]);

        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(Hash::check('changed-password', User::where('email', 'admin@example.com')->value('password')));
    }

    public function test_staff_are_linked_to_their_booths_only(): void
    {
        $this->seed(DatabaseSeeder::class);

        $staff1 = User::where('email', 'staff1@example.com')->first();
        $staff2 = User::where('email', 'staff2@example.com')->first();

        $this->assertEqualsCanonicalizing(['ファーストブレイク・バーガー', 'ベースライン・ラーメン'], $staff1->booths->pluck('name')->all());
        $this->assertSame(['ウィングス・グッズ東'], $staff2->booths->pluck('name')->all());
    }

    public function test_today_covers_every_congestion_and_stock_state_for_the_demo(): void
    {
        $this->seed(DatabaseSeeder::class);
        $today = Game::onDate(now())->first();

        $levels = GameBooth::where('game_id', $today->id)->whereNotNull('congestion_level')->get()->pluck('congestion_level')->map->value->unique()->sort()->values()->all();
        $this->assertSame(CongestionLevel::values(), $levels, '4 段階すべてがデモに含まれる');

        $this->assertSame(1, GameBooth::where('game_id', $today->id)->where('is_open', true)->whereNull('congestion_level')->count(), '営業中で未更新のブースが 1 つ');
        $this->assertSame(1, GameBooth::where('game_id', $today->id)->where('is_open', false)->count(), '閉店中のブースが 1 つ');
        $this->assertTrue(
            GameBooth::where('game_id', $today->id)->where('congestion_updated_at', '<', now()->subMinutes(config('courtq.stale_after_minutes')))->exists(),
            '更新が古いブースが含まれる',
        );

        $statuses = ItemStock::where('game_id', $today->id)->pluck('status')->map->value->unique()->sort()->values()->all();
        $this->assertSame(['in_stock', 'low', 'sold_out'], $statuses);
        $this->assertGreaterThan(0, CongestionLog::count());
        $this->assertGreaterThan(0, StockLog::count());
        $this->assertSame(MenuItem::count(), ItemStock::where('game_id', $today->id)->count(), '今日の試合は全メニューに在庫行がある');
    }

    public function test_demo_data_is_fictional(): void
    {
        $this->seed(DatabaseSeeder::class);

        $text = collect([
            Venue::pluck('name'), Game::pluck('home_team'), Game::pluck('away_team'), Booth::pluck('name'),
        ])->flatten()->implode(' ');

        foreach (['B.LEAGUE', 'Bリーグ', 'ＢＬＥＡＧＵＥ', 'B．LEAGUE'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text);
        }
        $this->assertStringContainsString('架空', Venue::first()->name);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'users' => User::count(), 'venues' => Venue::count(), 'games' => Game::count(), 'booths' => Booth::count(),
            'game_booths' => GameBooth::count(), 'menu_items' => MenuItem::count(), 'item_stocks' => ItemStock::count(),
            'booth_staff' => \DB::table('booth_staff')->count(), 'favorites' => \DB::table('favorites')->count(),
            'congestion_logs' => CongestionLog::count(), 'stock_logs' => StockLog::count(),
        ];
    }
}
