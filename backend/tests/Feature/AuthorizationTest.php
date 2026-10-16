<?php

namespace Tests\Feature;

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
use App\Services\ApiDocsGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 書き込み API（と役割が必要な API）の認可を、docs/progress.md「WP-B4 の認可ルール」の表のとおりに総当たりで検証する。
 *
 * 登場人物: 未ログイン / user / 担当なし staff / staff A（ブース A の担当）/ staff B（ブース B の担当）/ admin
 * 「staff A → B」は、staff A が自分のトークンでブース B（のメニュー）を操作する場合。
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    private Booth $boothA;

    private Booth $boothB;

    private MenuItem $itemA;

    private MenuItem $itemB;

    /** @var array<string, User> */
    private array $users = [];

    /**
     * 認可ルール表。値は HTTP ステータス（null は「この組み合わせは無い」）。
     * 書き込み API を追加したら、ここに行を足す（足さないと test_every_protected_route_is_in_the_matrix が失敗する）。
     */
    private const MATRIX = [
        //                                                                   未ログイン  user  担当なし  A→A   A→B   admin
        'GET /api/staff/booths' => ['guest' => 401, 'user' => 403, 'staff_none' => 200, 'staff_own' => 200, 'staff_other' => null, 'admin' => 200],
        'PUT /api/staff/games/{game}/booths/{booth}/congestion' => ['guest' => 401, 'user' => 403, 'staff_none' => 403, 'staff_own' => 200, 'staff_other' => 403, 'admin' => 200],
        'PUT /api/staff/games/{game}/menu-items/{menuItem}/stock' => ['guest' => 401, 'user' => 403, 'staff_none' => 403, 'staff_own' => 200, 'staff_other' => 403, 'admin' => 200],
        'GET /api/staff/booths/{booth}/menu-items' => ['guest' => 401, 'user' => 403, 'staff_none' => 403, 'staff_own' => 200, 'staff_other' => 403, 'admin' => 200],
        'POST /api/staff/booths/{booth}/menu-items' => ['guest' => 401, 'user' => 403, 'staff_none' => 403, 'staff_own' => 201, 'staff_other' => 403, 'admin' => 201],
        'PUT /api/staff/booths/{booth}/menu-items/{menuItem}' => ['guest' => 401, 'user' => 403, 'staff_none' => 403, 'staff_own' => 200, 'staff_other' => 403, 'admin' => 200],
        'DELETE /api/staff/booths/{booth}/menu-items/{menuItem}' => ['guest' => 401, 'user' => 403, 'staff_none' => 403, 'staff_own' => 200, 'staff_other' => 403, 'admin' => 200],
        'POST /api/admin/booths/{booth}/invitations' => ['guest' => 401, 'user' => 403, 'staff_none' => 403, 'staff_own' => 403, 'staff_other' => 403, 'admin' => 201],
        'GET /api/admin/booths/{booth}/staff' => ['guest' => 401, 'user' => 403, 'staff_none' => 403, 'staff_own' => 403, 'staff_other' => 403, 'admin' => 200],
        'DELETE /api/admin/booths/{booth}/staff/{user}' => ['guest' => 401, 'user' => 403, 'staff_none' => 403, 'staff_own' => 403, 'staff_other' => 403, 'admin' => 200],
    ];

    /**
     * 役割に関係なく、ログインしていれば自分のために使える書き込み API（認可表の対象外）。理由も書いておく。
     */
    private const SELF_SERVICE_WRITES = [
        'POST /api/auth/register' => '誰でも登録できる（role は受け取らない。test_role_sent_by_the_client_is_ignored）',
        'POST /api/auth/login' => 'ログイン',
        'POST /api/auth/logout' => '自分のトークンを消すだけ',
        'PUT /api/booths/{booth}/favorite' => '自分のお気に入りだけを変える（FavoriteApiTest）',
        'DELETE /api/booths/{booth}/favorite' => '自分のお気に入りだけを変える（FavoriteApiTest）',
        'POST /api/staff/invitations/accept' => '有効な招待コードを持つ人だけが担当になれる（StaffApiTest）',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->game = Game::factory()->today()->create();
        $this->boothA = Booth::factory()->create(['name' => 'ブース A']);
        $this->boothB = Booth::factory()->create(['name' => 'ブース B']);
        GameBooth::factory()->for($this->game)->for($this->boothA)->create();
        GameBooth::factory()->for($this->game)->for($this->boothB)->create();
        $this->itemA = MenuItem::factory()->create(['booth_id' => $this->boothA->id, 'name' => 'A のメニュー']);
        $this->itemB = MenuItem::factory()->create(['booth_id' => $this->boothB->id, 'name' => 'B のメニュー']);

        $this->users = [
            'user' => $this->userWithRole(UserRole::User),
            'staff_none' => $this->userWithRole(UserRole::Staff),
            'staff_a' => $this->userWithRole(UserRole::Staff, $this->boothA),
            'staff_b' => $this->userWithRole(UserRole::Staff, $this->boothB),
            'admin' => $this->userWithRole(UserRole::Admin),
        ];
    }

    private function userWithRole(UserRole $role, ?Booth $booth = null): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();
        if ($booth !== null) {
            $user->booths()->attach($booth->id);
        }

        return $user;
    }

    /**
     * 各 API の URL と正しいリクエスト本文を作る。$target はブース A / B のどちらを操作するか。
     *
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function request(string $key, string $target): array
    {
        $booth = $target === 'A' ? $this->boothA : $this->boothB;
        $item = $target === 'A' ? $this->itemA : $this->itemB;
        $member = $target === 'A' ? $this->users['staff_a'] : $this->users['staff_b'];
        $g = $this->game->id;

        return match ($key) {
            'GET /api/staff/booths' => ['GET', '/api/staff/booths', []],
            'PUT /api/staff/games/{game}/booths/{booth}/congestion' => ['PUT', "/api/staff/games/{$g}/booths/{$booth->id}/congestion", ['level' => 3, 'wait_minutes' => 20]],
            'PUT /api/staff/games/{game}/menu-items/{menuItem}/stock' => ['PUT', "/api/staff/games/{$g}/menu-items/{$item->id}/stock", ['status' => 'low', 'quantity' => 3]],
            'GET /api/staff/booths/{booth}/menu-items' => ['GET', "/api/staff/booths/{$booth->id}/menu-items", []],
            'POST /api/staff/booths/{booth}/menu-items' => ['POST', "/api/staff/booths/{$booth->id}/menu-items", ['name' => '新メニュー', 'kind' => 'food', 'price' => 500]],
            'PUT /api/staff/booths/{booth}/menu-items/{menuItem}' => ['PUT', "/api/staff/booths/{$booth->id}/menu-items/{$item->id}", ['price' => 999]],
            'DELETE /api/staff/booths/{booth}/menu-items/{menuItem}' => ['DELETE', "/api/staff/booths/{$booth->id}/menu-items/{$item->id}", []],
            'POST /api/admin/booths/{booth}/invitations' => ['POST', "/api/admin/booths/{$booth->id}/invitations", []],
            'GET /api/admin/booths/{booth}/staff' => ['GET', "/api/admin/booths/{$booth->id}/staff", []],
            'DELETE /api/admin/booths/{booth}/staff/{user}' => ['DELETE', "/api/admin/booths/{$booth->id}/staff/{$member->id}", []],
        };
    }

    /** @return array<string, array{0: string, 1: string, 2: int}> */
    public static function matrix(): array
    {
        $cases = [];
        foreach (self::MATRIX as $key => $expectations) {
            foreach ($expectations as $actor => $status) {
                if ($status !== null) {
                    $cases["{$actor} {$key}"] = [$key, $actor, $status];
                }
            }
        }

        return $cases;
    }

    /** 書き込みで変わりうるテーブルの中身（拒否されたら何も変わっていないことの確認に使う） */
    private function snapshot(): array
    {
        return [
            'game_booths' => DB::table('game_booths')->orderBy('id')->get()->toArray(),
            'congestion_logs' => CongestionLog::count(),
            'item_stocks' => ItemStock::count(),
            'stock_logs' => StockLog::count(),
            'menu_items' => DB::table('menu_items')->orderBy('id')->get()->toArray(),
            'booth_staff' => DB::table('booth_staff')->orderBy('id')->get(['booth_id', 'user_id'])->toArray(),
            'staff_invitations' => StaffInvitation::count(),
            'roles' => User::query()->orderBy('id')->pluck('role', 'id')->map(fn ($r) => $r->value)->all(),
        ];
    }

    #[DataProvider('matrix')]
    public function test_authorization_matrix(string $key, string $actor, int $expected): void
    {
        [$method, $url, $body] = $this->request($key, $actor === 'staff_other' || $actor === 'admin' ? 'B' : 'A');
        $user = match ($actor) {
            'guest' => null,
            'staff_own', 'staff_other' => $this->users['staff_a'],
            default => $this->users[$actor],
        };
        if ($user !== null) {
            Sanctum::actingAs($user);
        }
        $before = $this->snapshot();

        $response = $this->json($method, $url, $body);

        $response->assertStatus($expected);
        if ($expected === 403) {
            $response->assertExactJson(['message' => 'この操作は許可されていません']);
        }
        if ($expected >= 400) {
            $this->assertEquals($before, $this->snapshot(), "{$actor} が {$key} を拒否されたのに、DB が変わっています");
        }
    }

    /** 書き込み API と、役割が必要な API が、すべて上の表かセルフサービスの一覧に載っていること */
    public function test_every_protected_route_is_in_the_matrix(): void
    {
        Artisan::call('route:list', ['--json' => true, '--path' => 'api']);
        $routes = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $generator = app(ApiDocsGenerator::class);

        $mustBeCovered = [];
        foreach ($routes as $route) {
            $isWrite = preg_match('/POST|PUT|PATCH|DELETE/', $route['method']) === 1;
            $needsRole = collect($route['middleware'])->contains(fn ($m) => str_contains($m, 'EnsureRole'));
            if ($isWrite || $needsRole) {
                $mustBeCovered[] = $generator->keyFor($route);
            }
        }

        $covered = array_merge(array_keys(self::MATRIX), array_keys(self::SELF_SERVICE_WRITES));
        $this->assertSame([], array_values(array_diff($mustBeCovered, $covered)), '認可の表（AuthorizationTest::MATRIX）に載っていない書き込み API があります');
        $this->assertSame([], array_values(array_diff($covered, $mustBeCovered)), '存在しないルートが認可の表に残っています');
    }

    /** 役割が足りない人には、ID が存在するかどうかを教えない（存在しない ID でも 404 ではなく 403） */
    public function test_user_gets_403_even_for_ids_that_do_not_exist(): void
    {
        Sanctum::actingAs($this->users['user']);

        $this->putJson('/api/staff/games/999999/booths/999999/congestion', ['level' => 1])->assertForbidden();
        $this->putJson('/api/staff/games/999999/menu-items/999999/stock', ['status' => 'low'])->assertForbidden();
        $this->postJson('/api/staff/booths/999999/menu-items', [])->assertForbidden();
        $this->putJson('/api/staff/booths/999999/menu-items/999999', [])->assertForbidden();
        $this->deleteJson('/api/staff/booths/999999/menu-items/999999')->assertForbidden();
        $this->postJson('/api/admin/booths/999999/invitations')->assertForbidden();
        $this->getJson('/api/admin/booths/999999/staff')->assertForbidden();
        $this->deleteJson('/api/admin/booths/999999/staff/999999')->assertForbidden();
    }

    /** 他のブースへの不正な入力は、入力検証（422）より先に 403 になる */
    public function test_staff_of_another_booth_gets_403_before_validation(): void
    {
        Sanctum::actingAs($this->users['staff_a']);
        $g = $this->game->id;

        $this->putJson("/api/staff/games/{$g}/booths/{$this->boothB->id}/congestion", ['level' => 99])->assertForbidden();
        $this->putJson("/api/staff/games/{$g}/menu-items/{$this->itemB->id}/stock", ['status' => 'unknown'])->assertForbidden();
        $this->postJson("/api/staff/booths/{$this->boothB->id}/menu-items", ['price' => -1])->assertForbidden();
        $this->putJson("/api/staff/booths/{$this->boothB->id}/menu-items/{$this->itemB->id}", ['price' => -1])->assertForbidden();
    }

    /** 自分のブースの URL に他のブースのメニュー ID を入れても、そのメニューは見つからない（404）し、変わらない */
    public function test_menu_item_of_another_booth_cannot_be_reached_through_own_booth_url(): void
    {
        Sanctum::actingAs($this->users['staff_a']);
        $before = $this->snapshot();

        $this->putJson("/api/staff/booths/{$this->boothA->id}/menu-items/{$this->itemB->id}", ['price' => 1])->assertNotFound();
        $this->deleteJson("/api/staff/booths/{$this->boothA->id}/menu-items/{$this->itemB->id}")->assertNotFound();

        $this->assertEquals($before, $this->snapshot());
        $this->assertModelExists($this->itemB);
    }

    /** staff B から見ても同じ（A のブースを B が更新できない）。表は staff A の側だけなので、逆向きも確かめる */
    public function test_staff_b_cannot_update_booth_a_either(): void
    {
        Sanctum::actingAs($this->users['staff_b']);
        $g = $this->game->id;

        $this->putJson("/api/staff/games/{$g}/booths/{$this->boothA->id}/congestion", ['level' => 4, 'wait_minutes' => 60])->assertForbidden();
        $this->putJson("/api/staff/games/{$g}/menu-items/{$this->itemA->id}/stock", ['status' => 'sold_out'])->assertForbidden();
        $this->putJson("/api/staff/games/{$g}/booths/{$this->boothB->id}/congestion", ['level' => 1, 'wait_minutes' => 0])->assertOk();

        $this->assertSame(1, CongestionLog::count(), '自分のブース B の更新だけが記録される');
    }

    /** 役割はサーバーだけが決める。クライアントが role を送っても無視される */
    public function test_role_sent_by_the_client_is_ignored(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => '攻撃者', 'email' => 'attacker@example.com', 'password' => 'password123', 'password_confirmation' => 'password123', 'role' => 'admin',
        ])->assertCreated()->assertJsonPath('data.user.role', 'user');
        $attacker = User::where('email', 'attacker@example.com')->sole();
        $this->assertSame(UserRole::User, $attacker->role);

        Sanctum::actingAs($attacker);
        $this->postJson("/api/admin/booths/{$this->boothA->id}/invitations", ['role' => 'admin'])->assertForbidden();
        $this->putJson("/api/staff/games/{$this->game->id}/booths/{$this->boothA->id}/congestion", ['level' => 1, 'role' => 'admin'])->assertForbidden();
    }

    /** トークンを使ったまま役割が下がったら、次のリクエストからすぐ拒否される（役割は毎回 DB から読む） */
    public function test_demoted_staff_is_rejected_on_the_next_request(): void
    {
        $staff = $this->users['staff_a'];
        $token = $staff->createToken('test')->plainTextToken;
        $url = "/api/staff/games/{$this->game->id}/booths/{$this->boothA->id}/congestion";

        $this->withToken($token)->putJson($url, ['level' => 2, 'wait_minutes' => 5])->assertOk();

        Sanctum::actingAs($this->users['admin']);
        $this->deleteJson("/api/admin/booths/{$this->boothA->id}/staff/{$staff->id}")->assertOk();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->putJson($url, ['level' => 3, 'wait_minutes' => 10])->assertForbidden();
    }

    public function test_staff_write_api_is_rate_limited_per_user(): void
    {
        Sanctum::actingAs($this->users['staff_a']);
        $url = "/api/staff/games/{$this->game->id}/booths/{$this->boothA->id}/congestion";

        for ($i = 0; $i < 30; $i++) {
            $this->putJson($url, ['level' => 1, 'wait_minutes' => 0])->assertOk();
        }
        $this->putJson($url, ['level' => 1, 'wait_minutes' => 0])
            ->assertStatus(429)
            ->assertJson(['message' => 'リクエストが多すぎます。しばらくしてからもう一度お試しください']);

        // 別のスタッフは影響を受けない（ユーザーごとの制限）
        Sanctum::actingAs($this->users['staff_b']);
        $this->putJson("/api/staff/games/{$this->game->id}/booths/{$this->boothB->id}/congestion", ['level' => 1, 'wait_minutes' => 0])->assertOk();
    }

    public function test_stock_and_congestion_share_the_staff_write_limit(): void
    {
        Sanctum::actingAs($this->users['staff_a']);
        $g = $this->game->id;

        for ($i = 0; $i < 30; $i++) {
            $this->putJson("/api/staff/games/{$g}/menu-items/{$this->itemA->id}/stock", ['status' => 'in_stock'])->assertOk();
        }

        $this->putJson("/api/staff/games/{$g}/booths/{$this->boothA->id}/congestion", ['level' => 1])->assertStatus(429);
    }
}
