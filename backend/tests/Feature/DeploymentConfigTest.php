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
}
