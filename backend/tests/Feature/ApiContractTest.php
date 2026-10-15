<?php

namespace Tests\Feature;

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
}
