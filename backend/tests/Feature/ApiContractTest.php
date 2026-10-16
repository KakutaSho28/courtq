<?php

namespace Tests\Feature;

use App\Enums\CongestionLevel;
use App\Enums\StockStatus;
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
}
