<?php

namespace Tests\Feature;

use Tests\TestCase;

class DeploymentConfigTest extends TestCase
{
    public function test_cors_only_allows_configured_origins(): void
    {
        $this->assertNotContains('*', config('cors.allowed_origins'));

        config(['cors.allowed_origins' => ['https://courtq.vercel.app', 'https://courtq-staging.vercel.app']]);

        $this->withHeaders(['Origin' => 'https://courtq.vercel.app'])
            ->getJson('/api/health')
            ->assertHeader('Access-Control-Allow-Origin', 'https://courtq.vercel.app');

        $this->withHeaders(['Origin' => 'https://evil.example'])
            ->getJson('/api/health')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_forwarded_proto_makes_urls_https(): void
    {
        $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'api.example.com'])
            ->getJson('/api/health');

        $this->assertStringStartsWith('https://', url('/api/health'));
    }

    public function test_routes_are_cacheable(): void
    {
        // クロージャルートがあると本番の route:cache が失敗する
        $closures = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => $route->getAction('uses') instanceof \Closure)
            ->map(fn ($route) => $route->uri())
            ->all();

        $this->assertSame([], $closures, 'closure routes found: ' . implode(', ', $closures));
    }

    /** プリフライトの結果をブラウザにキャッシュさせる（0 だと、認証付きの全リクエストの前に確認の通信が毎回入る） */
    public function test_preflight_response_is_cacheable_by_the_browser(): void
    {
        config(['cors.allowed_origins' => ['https://courtq.vercel.app']]);

        $response = $this->withHeaders([
            'Origin' => 'https://courtq.vercel.app',
            'Access-Control-Request-Method' => 'PUT',
            'Access-Control-Request-Headers' => 'authorization,content-type',
        ])->options('/api/staff/booths');

        $response->assertNoContent();
        $response->assertHeader('Access-Control-Allow-Origin', 'https://courtq.vercel.app');
        $this->assertGreaterThanOrEqual(600, (int) $response->headers->get('Access-Control-Max-Age'));
        $this->assertStringContainsString('PUT', (string) $response->headers->get('Access-Control-Allow-Methods').',PUT'); // * を許可
    }

    // ===== デプロイ設定ファイル同士の整合（Railway / Vercel） =====

    private function readJson(string $path): array
    {
        return json_decode(file_get_contents(base_path($path)), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_railway_web_config_uses_the_dockerfile_and_the_health_check_that_verifies_the_database(): void
    {
        $web = $this->readJson('railway.json');

        $this->assertSame('DOCKERFILE', $web['build']['builder']);
        $this->assertSame('/api/health', $web['deploy']['healthcheckPath']);
        $this->assertGreaterThanOrEqual(120, $web['deploy']['healthcheckTimeout'], '初回は migrate があるので、余裕を持たせる');
        $this->assertSame('ON_FAILURE', $web['deploy']['restartPolicyType']);
        $this->assertArrayNotHasKey('startCommand', $web['deploy'], 'web は Dockerfile の CMD（start-web）で起動する');

        // ヘルスチェックのパスが実在し、DB の状態を返す
        $this->getJson('/api/health')->assertOk()->assertJsonPath('data.database', 'ok');
    }

    public function test_railway_worker_config_runs_the_queue_worker_and_checks_the_environment_first(): void
    {
        $worker = $this->readJson('railway.worker.json');

        $this->assertSame('DOCKERFILE', $worker['build']['builder']);
        $this->assertSame('ALWAYS', $worker['deploy']['restartPolicyType']);
        $this->assertArrayNotHasKey('healthcheckPath', $worker['deploy'], 'worker は HTTP を受けないので、ヘルスチェックは付けない');
        $command = $worker['deploy']['startCommand'];
        $this->assertStringContainsString('app:deploy-check --worker', $command);
        $this->assertStringContainsString('queue:work', $command);
        $this->assertLessThan(strpos($command, 'queue:work'), strpos($command, 'app:deploy-check'), '環境の確認が先');
        $this->assertContains('app:deploy-check', array_keys(\Illuminate\Support\Facades\Artisan::all()), '起動コマンドが呼ぶ artisan コマンドが存在する');
    }

    public function test_start_script_checks_the_environment_before_migrating_and_caches_afterwards(): void
    {
        $script = file_get_contents(base_path('docker/start-web.sh'));
        $positions = array_map(fn ($needle) => strpos($script, $needle), [
            'app:deploy-check --wait-db', 'php artisan migrate --force', 'config:cache', 'route:cache', 'exec supervisord',
        ]);

        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, '確認 → migrate → キャッシュ → 起動の順');
        $this->assertStringContainsString('set -e', $script, '失敗したら起動しない');
        $this->assertStringContainsString("envsubst '\${PORT}'", $script, 'Railway が割り当てる PORT で待ち受ける');
        $this->assertStringContainsString('listen ${PORT};', file_get_contents(base_path('docker/nginx.conf.template')));
    }

    public function test_dockerfile_starts_with_the_web_script_and_keeps_secrets_out_of_the_image(): void
    {
        $dockerfile = file_get_contents(base_path('Dockerfile'));
        $ignore = file_get_contents(base_path('.dockerignore'));

        $this->assertStringContainsString('CMD ["start-web"]', $dockerfile);
        $this->assertStringContainsString('APP_DEBUG=false', $dockerfile);
        $this->assertMatchesRegularExpression('/^\.env$/m', $ignore, '.env をイメージに入れない');
        $this->assertStringNotContainsString('APP_KEY', $dockerfile, '秘密をイメージに焼き込まない');
        $this->assertStringNotContainsString('DB_PASSWORD', $dockerfile);
    }

    public function test_vercel_config_rewrites_every_path_to_the_spa(): void
    {
        $vercel = json_decode(file_get_contents(base_path('../frontend/vercel.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('vite', $vercel['framework']);
        $this->assertSame('dist', $vercel['outputDirectory']);
        $this->assertContains(['source' => '/(.*)', 'destination' => '/index.html'], $vercel['rewrites'], 'ブラウザの更新・URL の直接入力（/games/1 など）で 404 にならない');
    }
}
