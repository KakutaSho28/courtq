<?php

namespace Tests\Feature;

use App\Services\ApiDocsGenerator;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * API 仕様書がコードとずれていないことを CI で保証する。
 */
class ApiDocsTest extends TestCase
{
    /** @return array<int, array<string, mixed>> */
    private function routes(): array
    {
        Artisan::call('route:list', ['--json' => true, '--path' => 'api']);

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array<string, mixed>> */
    private function examples(): array
    {
        return require base_path('docs/api-examples.php');
    }

    public function test_every_api_route_has_documentation_and_nothing_is_stale(): void
    {
        $generator = app(ApiDocsGenerator::class);
        $routeKeys = array_map(fn ($route) => $generator->keyFor($route), $this->routes());
        $exampleKeys = array_keys($this->examples());

        $this->assertSame([], array_values(array_diff($routeKeys, $exampleKeys)), 'ルートがあるのに backend/docs/api-examples.php に説明がありません');
        $this->assertSame([], array_values(array_diff($exampleKeys, $routeKeys)), 'api-examples.php に、存在しないルートの説明が残っています');
    }

    public function test_every_example_has_summary_group_and_documented_responses(): void
    {
        foreach ($this->examples() as $key => $example) {
            $this->assertNotEmpty($example['summary'] ?? null, "{$key}: summary がありません");
            $this->assertNotEmpty($example['group'] ?? null, "{$key}: group がありません");
            $this->assertNotEmpty($example['responses'] ?? null, "{$key}: responses がありません");
        }
    }

    public function test_committed_api_endpoints_md_is_up_to_date(): void
    {
        $path = base_path('../docs/api-endpoints.md');
        $this->assertFileExists($path);

        $expected = app(ApiDocsGenerator::class)->generate($this->routes(), $this->examples());

        $this->assertSame(
            $expected,
            file_get_contents($path),
            'docs/api-endpoints.md が古くなっています。`cd backend && php artisan docs:api` を実行してコミットしてください',
        );
    }

    public function test_validation_rules_come_from_form_requests(): void
    {
        $markdown = app(ApiDocsGenerator::class)->generate($this->routes(), $this->examples());

        $this->assertStringContainsString('| `email` | `required` `email` `unique:users,email` |', $markdown);
        $this->assertStringContainsString('| `password` | `required` `string` `min:8` `confirmed` |', $markdown);
    }
}
