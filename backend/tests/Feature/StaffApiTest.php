<?php

namespace Tests\Feature;

use App\Enums\CongestionLevel;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** スタッフ・管理者の API の正常系と業務ルール。誰が使えるかは AuthorizationTest で総当たりしている */
class StaffApiTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    private Booth $booth;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-16 19:30:00', 'Asia/Tokyo'));
        $this->game = Game::factory()->today()->create();
        $this->booth = Booth::factory()->create(['name' => 'から揚げスタンド']);
        GameBooth::factory()->for($this->game)->for($this->booth)->create();
        $this->staff = $this->userWithRole(UserRole::Staff);
        $this->staff->booths()->attach($this->booth->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function userWithRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    public function test_me_includes_role_and_assigned_booths(): void
    {
        Sanctum::actingAs($this->staff);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'staff')
            ->assertJsonPath('data.booths', [['booth_id' => $this->booth->id, 'name' => 'から揚げスタンド']]);
    }

    public function test_staff_booths_lists_own_booths_with_todays_game(): void
    {
        Booth::factory()->create(['name' => '他のブース']);
        Sanctum::actingAs($this->staff);

        $this->getJson('/api/staff/booths')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'から揚げスタンド')
            ->assertJsonPath('data.0.upcoming_games.0.game_id', $this->game->id)
            ->assertJsonPath('data.0.upcoming_games.0.congestion.level', null);
    }

    public function test_admin_sees_every_booth(): void
    {
        Booth::factory()->create(['name' => '他のブース']);
        Sanctum::actingAs($this->userWithRole(UserRole::Admin));

        $this->getJson('/api/staff/booths')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_congestion_update_returns_new_value_and_is_visible_to_visitors_immediately(): void
    {
        $visitor = User::factory()->create();
        Sanctum::actingAs($visitor);
        $this->getJson("/api/games/{$this->game->id}/booths")->assertJsonPath('data.0.congestion.level', null); // キャッシュに載る

        Sanctum::actingAs($this->staff);
        $this->putJson("/api/staff/games/{$this->game->id}/booths/{$this->booth->id}/congestion", ['level' => 4, 'wait_minutes' => 45])
            ->assertOk()
            ->assertJsonPath('data.congestion.level', 4)
            ->assertJsonPath('data.congestion.label', '非常に混雑')
            ->assertJsonPath('data.congestion.wait_minutes', 45)
            ->assertJsonPath('data.congestion.updated_at', '2026-10-16T19:30:00+09:00')
            ->assertJsonPath('data.congestion.is_stale', false);

        Sanctum::actingAs($visitor);
        $this->getJson("/api/games/{$this->game->id}/booths")
            ->assertJsonPath('data.0.congestion.level', 4)
            ->assertJsonPath('data.0.congestion.wait_minutes', 45);
        $this->getJson("/api/games/{$this->game->id}/booths/{$this->booth->id}/congestion-history")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.level', 4);
        $this->assertSame($this->staff->id, CongestionLog::sole()->user_id);
    }

    public function test_congestion_wait_minutes_may_be_omitted_or_null(): void
    {
        Sanctum::actingAs($this->staff);
        $url = "/api/staff/games/{$this->game->id}/booths/{$this->booth->id}/congestion";

        $this->putJson($url, ['level' => 2])->assertOk()->assertJsonPath('data.congestion.wait_minutes', null);
        $this->putJson($url, ['level' => 2, 'wait_minutes' => null])->assertOk();
    }

    public function test_congestion_validation(): void
    {
        Sanctum::actingAs($this->staff);
        $url = "/api/staff/games/{$this->game->id}/booths/{$this->booth->id}/congestion";

        $this->putJson($url, [])->assertUnprocessable()->assertJsonPath('errors.level.0', '混雑レベル を入力してください');
        $this->putJson($url, ['level' => 0])->assertUnprocessable()->assertJsonValidationErrors('level');
        $this->putJson($url, ['level' => 5])->assertUnprocessable()->assertJsonValidationErrors('level');
        $this->putJson($url, ['level' => 2, 'wait_minutes' => -1])->assertUnprocessable()->assertJsonValidationErrors('wait_minutes');
        $this->putJson($url, ['level' => 2, 'wait_minutes' => 181])
            ->assertUnprocessable()
            ->assertJsonPath('errors.wait_minutes.0', '待ち時間 は 180 以下で入力してください');
        $this->putJson($url, ['level' => 2, 'wait_minutes' => 180])->assertOk();
        $this->putJson($url, ['level' => 2, 'wait_minutes' => 1.5])->assertUnprocessable();
        $this->assertSame(1, CongestionLog::count(), '不正な入力では記録されない');
    }

    public function test_congestion_for_a_booth_not_in_the_game_is_404(): void
    {
        $other = Game::factory()->create();
        Sanctum::actingAs($this->staff);

        $this->putJson("/api/staff/games/{$other->id}/booths/{$this->booth->id}/congestion", ['level' => 1])
            ->assertNotFound()
            ->assertJson(['message' => 'この試合に出店していないブースです']);
    }

    public function test_stock_update_creates_then_updates_with_logs(): void
    {
        $item = MenuItem::factory()->create(['booth_id' => $this->booth->id]);
        Sanctum::actingAs($this->staff);
        $url = "/api/staff/games/{$this->game->id}/menu-items/{$item->id}/stock";

        $this->putJson($url, ['status' => 'in_stock'])
            ->assertOk()
            ->assertJsonPath('data.menu_item_id', $item->id)
            ->assertJsonPath('data.stock.status', 'in_stock')
            ->assertJsonPath('data.stock.label', '在庫あり')
            ->assertJsonPath('data.stock.quantity', null);
        $this->putJson($url, ['status' => 'sold_out', 'quantity' => 0])
            ->assertOk()
            ->assertJsonPath('data.stock.label', '売り切れ')
            ->assertJsonPath('data.stock.quantity', 0);

        $this->assertSame(StockStatus::SoldOut, ItemStock::sole()->status);
        $this->assertSame(2, StockLog::count());

        $this->getJson("/api/games/{$this->game->id}/booths/{$this->booth->id}")->assertJsonPath('data.menu_items.0.stock.status', 'sold_out');
    }

    public function test_stock_validation(): void
    {
        $item = MenuItem::factory()->create(['booth_id' => $this->booth->id]);
        Sanctum::actingAs($this->staff);
        $url = "/api/staff/games/{$this->game->id}/menu-items/{$item->id}/stock";

        $this->putJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->putJson($url, ['status' => 'few'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->putJson($url, ['status' => 'low', 'quantity' => -1])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame(0, StockLog::count());
    }

    public function test_stock_for_a_game_the_booth_is_not_in_is_404(): void
    {
        $item = MenuItem::factory()->create(['booth_id' => $this->booth->id]);
        $other = Game::factory()->create();
        Sanctum::actingAs($this->staff);

        $this->putJson("/api/staff/games/{$other->id}/menu-items/{$item->id}/stock", ['status' => 'low'])->assertNotFound();
        $this->assertSame(0, ItemStock::count());
    }

    public function test_menu_item_crud(): void
    {
        Sanctum::actingAs($this->staff);
        $base = "/api/staff/booths/{$this->booth->id}/menu-items";

        $id = $this->postJson($base, ['name' => 'から揚げ 5 個', 'kind' => 'food', 'price' => 700, 'booth_id' => 999])
            ->assertCreated()
            ->assertJsonPath('data.booth_id', $this->booth->id)
            ->assertJsonPath('data.is_active', true)
            ->json('data.id');

        $this->putJson("{$base}/{$id}", ['price' => 800, 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.price', 800)
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.name', 'から揚げ 5 個');

        $this->deleteJson("{$base}/{$id}")->assertOk()->assertJson(['message' => 'メニューを削除しました']);
        $this->assertDatabaseMissing('menu_items', ['id' => $id]);
    }

    public function test_menu_item_list_includes_inactive_items(): void
    {
        MenuItem::factory()->create(['booth_id' => $this->booth->id, 'name' => '販売中']);
        MenuItem::factory()->inactive()->create(['booth_id' => $this->booth->id, 'name' => '販売終了']);
        MenuItem::factory()->create(); // 別のブース
        Sanctum::actingAs($this->staff);

        $response = $this->getJson("/api/staff/booths/{$this->booth->id}/menu-items")->assertOk()->assertJsonCount(2, 'data');

        $this->assertEqualsCanonicalizing(['販売中', '販売終了'], $response->json('data.*.name'));
        $this->assertContains(false, $response->json('data.*.is_active'));
    }

    public function test_menu_item_validation(): void
    {
        Sanctum::actingAs($this->staff);
        $base = "/api/staff/booths/{$this->booth->id}/menu-items";

        $this->postJson($base, [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'kind', 'price']);
        $this->postJson($base, ['name' => 'x', 'kind' => 'drink', 'price' => 100])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->postJson($base, ['name' => 'x', 'kind' => 'food', 'price' => -1])->assertUnprocessable()->assertJsonValidationErrors('price');
        $item = MenuItem::factory()->create(['booth_id' => $this->booth->id]);
        $this->putJson("{$base}/{$item->id}", ['name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_menu_item_with_stock_records_cannot_be_deleted(): void
    {
        $item = MenuItem::factory()->create(['booth_id' => $this->booth->id]);
        ItemStock::factory()->for($this->game)->for($item, 'menuItem')->create();
        Sanctum::actingAs($this->staff);

        $this->deleteJson("/api/staff/booths/{$this->booth->id}/menu-items/{$item->id}")
            ->assertUnprocessable()
            ->assertJson(['message' => '在庫の記録があるメニューは削除できません。販売をやめるときは「販売中」をオフにしてください']);
        $this->assertModelExists($item);
    }

    /** 受け入れ条件: 管理者が発行した招待コードで、一般ユーザーがそのブースのスタッフになる */
    public function test_invitation_flow_turns_a_user_into_staff_of_that_booth_only(): void
    {
        $admin = $this->userWithRole(UserRole::Admin);
        $other = Booth::factory()->create();
        GameBooth::factory()->for($this->game)->for($other)->create();
        $user = $this->userWithRole(UserRole::User);

        Sanctum::actingAs($admin);
        $code = $this->postJson("/api/admin/booths/{$this->booth->id}/invitations")
            ->assertCreated()
            ->assertJsonPath('data.booth.booth_id', $this->booth->id)
            ->assertJsonPath('data.expires_at', '2026-10-23T19:30:00+09:00')
            ->json('data.code');
        $this->assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}$/', $code);

        Sanctum::actingAs($user);
        $this->putJson("/api/staff/games/{$this->game->id}/booths/{$this->booth->id}/congestion", ['level' => 1])->assertForbidden();
        $this->postJson('/api/staff/invitations/accept', ['code' => strtolower($code)])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'staff')
            ->assertJsonPath('data.user.booths.0.booth_id', $this->booth->id)
            ->assertJsonPath('data.booth.name', 'から揚げスタンド');

        $user->refresh();
        Sanctum::actingAs($user);
        $this->putJson("/api/staff/games/{$this->game->id}/booths/{$this->booth->id}/congestion", ['level' => 1])->assertOk();
        $this->putJson("/api/staff/games/{$this->game->id}/booths/{$other->id}/congestion", ['level' => 1])->assertForbidden();

        // 使用済みのコードは、別の人には使えない
        Sanctum::actingAs($this->userWithRole(UserRole::User));
        $this->postJson('/api/staff/invitations/accept', ['code' => $code])
            ->assertUnprocessable()
            ->assertExactJson(['message' => '招待コードが正しくないか、有効期限が切れています']);
    }

    public function test_expired_invitation_is_rejected_with_the_same_message(): void
    {
        StaffInvitation::factory()->create(['booth_id' => $this->booth->id, 'code' => 'ABCD2345', 'expires_at' => now()->subMinute()]);
        Sanctum::actingAs($user = $this->userWithRole(UserRole::User));

        $this->postJson('/api/staff/invitations/accept', ['code' => 'ABCD-2345'])
            ->assertUnprocessable()
            ->assertExactJson(['message' => '招待コードが正しくないか、有効期限が切れています']);
        $this->assertSame(UserRole::User, $user->refresh()->role);
    }

    public function test_invitation_accept_requires_a_code(): void
    {
        Sanctum::actingAs($this->userWithRole(UserRole::User));

        $this->postJson('/api/staff/invitations/accept', [])->assertUnprocessable()->assertJsonPath('errors.code.0', '招待コード を入力してください');
    }

    public function test_invitation_accept_is_rate_limited_against_brute_force(): void
    {
        Sanctum::actingAs($this->userWithRole(UserRole::User));

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/staff/invitations/accept', ['code' => sprintf('WRONG%03d', $i)])->assertUnprocessable();
        }
        $this->postJson('/api/staff/invitations/accept', ['code' => 'WRONG999'])->assertStatus(429);
    }

    public function test_admin_lists_and_removes_staff_and_the_last_removal_demotes_to_user(): void
    {
        Sanctum::actingAs($this->userWithRole(UserRole::Admin));

        $this->getJson("/api/admin/booths/{$this->booth->id}/staff")
            ->assertOk()
            ->assertJsonPath('data.0.user_id', $this->staff->id)
            ->assertJsonPath('data.0.role', 'staff');

        $this->deleteJson("/api/admin/booths/{$this->booth->id}/staff/{$this->staff->id}")->assertOk()->assertJson(['message' => '担当を解除しました']);
        $this->assertSame(UserRole::User, $this->staff->refresh()->role);
        $this->getJson("/api/admin/booths/{$this->booth->id}/staff")->assertOk()->assertJsonCount(0, 'data');

        $this->deleteJson("/api/admin/booths/{$this->booth->id}/staff/{$this->staff->id}")
            ->assertNotFound()
            ->assertJson(['message' => 'このブースの担当ではありません']);
    }
}
