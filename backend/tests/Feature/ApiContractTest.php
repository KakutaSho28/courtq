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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * docs/api-examples.php の「レスポンス例」が、実際の API のレスポンスと同じ構造（キー）であることを確認する。
 * 例だけが古くなって、ドキュメントが嘘をつくのを防ぐ。値は比較しない（null の項目は何でも許可）。
 */
class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array<string, mixed>> */
    private array $examples;

    protected function setUp(): void
    {
        parent::setUp();
        $this->examples = require base_path('docs/api-examples.php');
        Event::fake(); // ブロードキャストは別のテストで検証する
    }

    private function assertDocumented(string $key, TestResponse $response): void
    {
        $status = $response->getStatusCode();
        $documented = $this->examples[$key]['responses'][$status] ?? null;
        $this->assertNotNull($documented, "{$key} が返した {$status} が api-examples.php に載っていません");

        if (array_key_exists('example', $documented)) {
            $this->assertShape($documented['example'], $response->json(), "{$key} [{$status}]");
        }
    }

    private function assertShape(mixed $expected, mixed $actual, string $path): void
    {
        if ($expected === null) {
            return; // null は「任意」
        }
        if (! is_array($expected)) {
            $this->assertFalse(is_array($actual), "{$path}: スカラーのはずが配列です");

            return;
        }
        $this->assertIsArray($actual, "{$path}: 配列/オブジェクトのはずです");

        if (array_is_list($expected)) {
            if ($expected !== [] && $actual !== []) {
                $this->assertShape($expected[0], $actual[0], "{$path}[0]");
            }

            return;
        }

        $this->assertEqualsCanonicalizing(array_keys($expected), array_keys($actual), "{$path}: キーがドキュメントの例と一致しません");
        foreach ($expected as $key => $value) {
            $this->assertShape($value, $actual[$key], "{$path}.{$key}");
        }
    }

    public function test_health_endpoint(): void
    {
        $this->assertDocumented('GET /api/health', $this->getJson('/api/health')->assertOk());
    }

    public function test_auth_endpoints(): void
    {
        $r = $this->postJson('/api/auth/register', ['name' => 'A', 'email' => 'a@example.com', 'password' => 'password123', 'password_confirmation' => 'password123']);
        $this->assertDocumented('POST /api/auth/register', $r->assertCreated());
        $this->assertDocumented('POST /api/auth/register', $this->postJson('/api/auth/register', ['name' => 'A', 'email' => 'a@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertUnprocessable());

        $this->assertDocumented('POST /api/auth/login', $this->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => 'password123'])->assertOk());
        $this->assertDocumented('POST /api/auth/login', $this->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => 'wrong-password'])->assertUnauthorized());

        $token = $this->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => 'password123'])->json('data.token');
        $this->assertDocumented('GET /api/me', $this->withToken($token)->getJson('/api/me')->assertOk());
        $this->assertDocumented('POST /api/auth/logout', $this->withToken($token)->postJson('/api/auth/logout')->assertOk());
    }

    public function test_unauthenticated_responses_are_documented(): void
    {
        $this->assertDocumented('GET /api/me', $this->getJson('/api/me')->assertUnauthorized());
        $this->assertDocumented('POST /api/auth/logout', $this->postJson('/api/auth/logout')->assertUnauthorized());
    }

    public function test_throttled_login_is_documented(): void
    {
        User::factory()->create(['email' => 'a@example.com']);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => 'wrong-password']);
        }

        $this->assertDocumented('POST /api/auth/login', $this->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => 'wrong-password'])->assertStatus(429));
    }

    /** 閲覧系 API（試合・ブース・お気に入り）の 200 / 401 / 404 / 422 が、api-examples.php の例と同じ構造 */
    public function test_read_endpoints_match_documented_examples(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->today()->create();
        $booth = Booth::factory()->create();
        $gameBooth = GameBooth::factory()->for($game)->for($booth)->withCongestion(CongestionLevel::Crowded, 25)->create();
        $item = MenuItem::factory()->create(['booth_id' => $booth->id, 'description' => '説明']);
        ItemStock::factory()->for($game)->for($item, 'menuItem')->status(StockStatus::Low, 4)->create();
        (new CongestionLog)->forceFill(['game_booth_id' => $gameBooth->id, 'level' => 3, 'wait_minutes' => 25, 'user_id' => $user->id, 'created_at' => now()])->save();
        $this->actingAs($user, 'sanctum');
        $base = "/api/games/{$game->id}";

        $this->assertDocumented('GET /api/games', $this->getJson('/api/games')->assertOk());
        $this->assertDocumented('GET /api/games', $this->getJson('/api/games?date=bad')->assertUnprocessable());
        $this->assertDocumented('GET /api/games/{game}', $this->getJson($base)->assertOk());
        $this->assertDocumented('GET /api/games/{game}', $this->getJson('/api/games/999999')->assertNotFound());

        $this->assertDocumented('GET /api/games/{game}/booths', $this->getJson("{$base}/booths")->assertOk());
        $this->assertDocumented('GET /api/games/{game}/booths', $this->getJson("{$base}/booths?sort=bad")->assertUnprocessable());
        $this->assertDocumented('GET /api/games/{game}/booths', $this->getJson('/api/games/999999/booths')->assertNotFound());
        $this->assertDocumented('GET /api/games/{game}/booths/{booth}', $this->getJson("{$base}/booths/{$booth->id}")->assertOk());
        $this->assertDocumented('GET /api/games/{game}/booths/{booth}', $this->getJson("{$base}/booths/".Booth::factory()->create()->id)->assertNotFound());
        $this->assertDocumented('GET /api/games/{game}/booths/{booth}/congestion-history', $this->getJson("{$base}/booths/{$booth->id}/congestion-history")->assertOk());
        $this->assertDocumented('GET /api/games/{game}/booths/{booth}/congestion-history', $this->getJson("{$base}/booths/999999/congestion-history")->assertNotFound());

        $this->assertDocumented('PUT /api/booths/{booth}/favorite', $this->putJson("/api/booths/{$booth->id}/favorite")->assertOk());
        $this->assertDocumented('PUT /api/booths/{booth}/favorite', $this->putJson('/api/booths/999999/favorite')->assertNotFound());
        $this->assertDocumented('GET /api/me/favorites', $this->getJson('/api/me/favorites')->assertOk());
        $this->assertDocumented('DELETE /api/booths/{booth}/favorite', $this->deleteJson("/api/booths/{$booth->id}/favorite")->assertOk());
        $this->assertDocumented('DELETE /api/booths/{booth}/favorite', $this->deleteJson('/api/booths/999999/favorite')->assertNotFound());
    }

    public function test_read_endpoints_document_the_unauthenticated_response(): void
    {
        $this->assertDocumented('GET /api/games', $this->getJson('/api/games')->assertUnauthorized());
        $this->assertDocumented('GET /api/games/{game}', $this->getJson('/api/games/1')->assertUnauthorized());
        $this->assertDocumented('GET /api/games/{game}/booths', $this->getJson('/api/games/1/booths')->assertUnauthorized());
        $this->assertDocumented('GET /api/games/{game}/booths/{booth}', $this->getJson('/api/games/1/booths/1')->assertUnauthorized());
        $this->assertDocumented('GET /api/games/{game}/booths/{booth}/congestion-history', $this->getJson('/api/games/1/booths/1/congestion-history')->assertUnauthorized());
        $this->assertDocumented('GET /api/me/favorites', $this->getJson('/api/me/favorites')->assertUnauthorized());
        $this->assertDocumented('PUT /api/booths/{booth}/favorite', $this->putJson('/api/booths/1/favorite')->assertUnauthorized());
        $this->assertDocumented('DELETE /api/booths/{booth}/favorite', $this->deleteJson('/api/booths/1/favorite')->assertUnauthorized());
    }

    private function userWithRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    /** スタッフ・管理者の API の応答が、api-examples.php の例と同じ構造 */
    public function test_staff_and_admin_endpoints_match_documented_examples(): void
    {
        $game = Game::factory()->today()->create();
        $booth = Booth::factory()->create();
        GameBooth::factory()->for($game)->for($booth)->create();
        $item = MenuItem::factory()->create(['booth_id' => $booth->id]);
        $staff = $this->userWithRole(UserRole::Staff);
        $staff->booths()->attach($booth->id);
        $admin = $this->userWithRole(UserRole::Admin);
        $user = $this->userWithRole(UserRole::User);
        $congestion = "/api/staff/games/{$game->id}/booths/{$booth->id}/congestion";
        $stock = "/api/staff/games/{$game->id}/menu-items/{$item->id}/stock";
        $menu = "/api/staff/booths/{$booth->id}/menu-items";

        $this->actingAs($staff, 'sanctum');
        $this->assertDocumented('GET /api/me', $this->getJson('/api/me')->assertOk());
        $this->assertDocumented('GET /api/staff/booths', $this->getJson('/api/staff/booths')->assertOk());
        $this->assertDocumented('PUT /api/staff/games/{game}/booths/{booth}/congestion', $this->putJson($congestion, ['level' => 3, 'wait_minutes' => 25])->assertOk());
        $this->assertDocumented('PUT /api/staff/games/{game}/booths/{booth}/congestion', $this->putJson($congestion, ['level' => 9])->assertUnprocessable());
        $this->assertDocumented('PUT /api/staff/games/{game}/booths/{booth}/congestion', $this->putJson("/api/staff/games/".Game::factory()->create()->id."/booths/{$booth->id}/congestion", ['level' => 1])->assertNotFound());
        $this->assertDocumented('PUT /api/staff/games/{game}/menu-items/{menuItem}/stock', $this->putJson($stock, ['status' => 'low', 'quantity' => 4])->assertOk());
        $this->assertDocumented('PUT /api/staff/games/{game}/menu-items/{menuItem}/stock', $this->putJson($stock, ['status' => 'bad'])->assertUnprocessable());
        $this->assertDocumented('PUT /api/staff/games/{game}/menu-items/{menuItem}/stock', $this->putJson("/api/staff/games/".Game::factory()->create()->id."/menu-items/{$item->id}/stock", ['status' => 'low'])->assertNotFound());
        $this->assertDocumented('GET /api/staff/booths/{booth}/menu-items', $this->getJson($menu)->assertOk());
        $this->assertDocumented('GET /api/staff/booths/{booth}/menu-items', $this->getJson('/api/staff/booths/999999/menu-items')->assertNotFound());
        $created = $this->postJson($menu, ['name' => 'から揚げ', 'kind' => 'food', 'price' => 700]);
        $this->assertDocumented('POST /api/staff/booths/{booth}/menu-items', $created->assertCreated());
        $this->assertDocumented('POST /api/staff/booths/{booth}/menu-items', $this->postJson($menu, [])->assertUnprocessable());
        $newId = $created->json('data.id');
        $this->assertDocumented('PUT /api/staff/booths/{booth}/menu-items/{menuItem}', $this->putJson("{$menu}/{$newId}", ['price' => 800])->assertOk());
        $this->assertDocumented('PUT /api/staff/booths/{booth}/menu-items/{menuItem}', $this->putJson("{$menu}/{$newId}", ['price' => -1])->assertUnprocessable());
        $this->assertDocumented('PUT /api/staff/booths/{booth}/menu-items/{menuItem}', $this->putJson("{$menu}/999999", ['price' => 1])->assertNotFound());
        $this->assertDocumented('DELETE /api/staff/booths/{booth}/menu-items/{menuItem}', $this->deleteJson("{$menu}/{$item->id}")->assertUnprocessable()); // 在庫の記録あり
        $this->assertDocumented('DELETE /api/staff/booths/{booth}/menu-items/{menuItem}', $this->deleteJson("{$menu}/{$newId}")->assertOk());
        $this->assertDocumented('DELETE /api/staff/booths/{booth}/menu-items/{menuItem}', $this->deleteJson("{$menu}/{$newId}")->assertNotFound());
        $this->assertDocumented('POST /api/staff/booths/{booth}/menu-items', $this->postJson('/api/staff/booths/999999/menu-items', [])->assertNotFound());

        $this->actingAs($admin, 'sanctum');
        $invite = $this->postJson("/api/admin/booths/{$booth->id}/invitations");
        $this->assertDocumented('POST /api/admin/booths/{booth}/invitations', $invite->assertCreated());
        $this->assertDocumented('POST /api/admin/booths/{booth}/invitations', $this->postJson('/api/admin/booths/999999/invitations')->assertNotFound());
        $this->assertDocumented('GET /api/admin/booths/{booth}/staff', $this->getJson("/api/admin/booths/{$booth->id}/staff")->assertOk());
        $this->assertDocumented('GET /api/admin/booths/{booth}/staff', $this->getJson('/api/admin/booths/999999/staff')->assertNotFound());

        $this->actingAs($user, 'sanctum');
        $this->assertDocumented('POST /api/staff/invitations/accept', $this->postJson('/api/staff/invitations/accept', ['code' => 'ZZZZ-ZZZZ'])->assertUnprocessable());
        $this->assertDocumented('POST /api/staff/invitations/accept', $this->postJson('/api/staff/invitations/accept', ['code' => $invite->json('data.code')])->assertOk());

        $this->actingAs($admin, 'sanctum');
        $this->assertDocumented('DELETE /api/admin/booths/{booth}/staff/{user}', $this->deleteJson("/api/admin/booths/{$booth->id}/staff/{$user->id}")->assertOk());
        $this->assertDocumented('DELETE /api/admin/booths/{booth}/staff/{user}', $this->deleteJson("/api/admin/booths/{$booth->id}/staff/{$user->id}")->assertNotFound());
    }

    public function test_staff_and_admin_endpoints_document_401_403_and_429(): void
    {
        $game = Game::factory()->today()->create();
        $booth = Booth::factory()->create();
        GameBooth::factory()->for($game)->for($booth)->create();
        $item = MenuItem::factory()->create(['booth_id' => $booth->id]);
        $calls = [
            'GET /api/staff/booths' => ['GET', '/api/staff/booths'],
            'PUT /api/staff/games/{game}/booths/{booth}/congestion' => ['PUT', "/api/staff/games/{$game->id}/booths/{$booth->id}/congestion"],
            'PUT /api/staff/games/{game}/menu-items/{menuItem}/stock' => ['PUT', "/api/staff/games/{$game->id}/menu-items/{$item->id}/stock"],
            'GET /api/staff/booths/{booth}/menu-items' => ['GET', "/api/staff/booths/{$booth->id}/menu-items"],
            'POST /api/staff/booths/{booth}/menu-items' => ['POST', "/api/staff/booths/{$booth->id}/menu-items"],
            'PUT /api/staff/booths/{booth}/menu-items/{menuItem}' => ['PUT', "/api/staff/booths/{$booth->id}/menu-items/{$item->id}"],
            'DELETE /api/staff/booths/{booth}/menu-items/{menuItem}' => ['DELETE', "/api/staff/booths/{$booth->id}/menu-items/{$item->id}"],
            'POST /api/admin/booths/{booth}/invitations' => ['POST', "/api/admin/booths/{$booth->id}/invitations"],
            'GET /api/admin/booths/{booth}/staff' => ['GET', "/api/admin/booths/{$booth->id}/staff"],
            'DELETE /api/admin/booths/{booth}/staff/{user}' => ['DELETE', "/api/admin/booths/{$booth->id}/staff/1"],
        ];

        foreach ($calls as $key => [$method, $url]) {
            $this->assertDocumented($key, $this->json($method, $url)->assertUnauthorized());
        }
        $this->assertDocumented('POST /api/staff/invitations/accept', $this->postJson('/api/staff/invitations/accept')->assertUnauthorized());

        $this->actingAs($this->userWithRole(UserRole::User), 'sanctum');
        foreach ($calls as $key => [$method, $url]) {
            $this->assertDocumented($key, $this->json($method, $url)->assertForbidden());
        }

        $staff = $this->userWithRole(UserRole::Staff);
        $staff->booths()->attach($booth->id);
        $this->actingAs($staff, 'sanctum');
        for ($i = 0; $i < 30; $i++) {
            $this->putJson($calls['PUT /api/staff/games/{game}/booths/{booth}/congestion'][1], ['level' => 1]);
        }
        $this->assertDocumented('PUT /api/staff/games/{game}/booths/{booth}/congestion', $this->putJson($calls['PUT /api/staff/games/{game}/booths/{booth}/congestion'][1], ['level' => 1])->assertStatus(429));
        $this->assertDocumented('PUT /api/staff/games/{game}/menu-items/{menuItem}/stock', $this->putJson($calls['PUT /api/staff/games/{game}/menu-items/{menuItem}/stock'][1], ['status' => 'low'])->assertStatus(429));

        $this->actingAs($this->userWithRole(UserRole::User), 'sanctum');
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/staff/invitations/accept', ['code' => 'ZZZZZZZZ']);
        }
        $this->assertDocumented('POST /api/staff/invitations/accept', $this->postJson('/api/staff/invitations/accept', ['code' => 'ZZZZZZZZ'])->assertStatus(429));
    }
}
