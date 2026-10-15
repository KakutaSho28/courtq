<?php

namespace Tests\Feature;

use App\Services\HealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_is_public_and_reports_database_ok(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson(['data' => ['status' => 'ok', 'database' => 'ok']]);
    }

    public function test_health_returns_503_when_database_is_down(): void
    {
        DB::shouldReceive('select')->andThrow(new \PDOException('gone away'));

        $this->assertSame(['status' => 'error', 'database' => 'error'], app(HealthService::class)->check());
        $this->getJson('/api/health')->assertStatus(503)->assertJsonPath('data.status', 'error');
    }
}
