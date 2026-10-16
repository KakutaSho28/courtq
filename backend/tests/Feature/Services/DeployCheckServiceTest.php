<?php

namespace Tests\Feature\Services;

use App\Services\DeployCheckService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 本番の起動前の設定チェック。「正しい設定なら通る」ことと、Railway の初回デプロイでありがちな間違いを
 * 1 つずつ検出することを確かめる（DB の接続先には触れない。設定値だけを見る）。
 */
class DeployCheckServiceTest extends TestCase
{
    /** 正しい本番の設定（これを基準に、1 か所ずつ壊す） */
    private function productionConfig(array $overrides = []): void
    {
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => 'https://courtq-api.up.railway.app',
            'app.frontend_url' => 'https://courtq.vercel.app',
            'database.default' => 'mysql',
            'database.connections.mysql.host' => 'mysql.railway.internal',
            'database.connections.mysql.port' => '3306',
            'database.connections.mysql.database' => 'railway',
            'database.connections.mysql.username' => 'root',
            'database.connections.mysql.password' => 'secret-password-value',
            'cors.allowed_origins' => ['https://courtq.vercel.app'],
            'cors.allowed_origins_patterns' => [],
            'queue.default' => 'database',
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'key',
            'broadcasting.connections.pusher.secret' => 'secret',
            'broadcasting.connections.pusher.app_id' => '1',
            'broadcasting.connections.pusher.options.cluster' => 'ap3',
            ...$overrides,
        ]);
    }

    public function test_a_correct_production_configuration_has_no_errors_or_warnings(): void
    {
        $this->productionConfig();

        $this->assertSame(['errors' => [], 'warnings' => []], app(DeployCheckService::class)->check());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function mistakes(): array
    {
        return [
            'APP_KEY が未設定' => [['app.key' => ''], 'APP_KEY が未設定'],
            'APP_KEY の形式が違う' => [['app.key' => 'base64:short'], 'APP_KEY の形式'],
            'APP_DEBUG が true' => [['app.debug' => true], 'APP_DEBUG が true'],
            'APP_URL がローカル' => [['app.url' => 'http://localhost'], 'APP_URL がローカル'],
            'APP_URL が未設定' => [['app.url' => ''], 'APP_URL がローカル'],
            'APP_URL が http' => [['app.url' => 'http://courtq-api.up.railway.app'], 'APP_URL が https://'],
            'DB が mysql でない' => [['database.default' => 'sqlite'], 'DB_CONNECTION が mysql ではありません'],
            'DB_HOST が 127.0.0.1（Railway が提案するローカルの値）' => [['database.connections.mysql.host' => '127.0.0.1'], 'DB_HOST がローカルの値'],
            'DB_HOST が localhost' => [['database.connections.mysql.host' => 'localhost'], 'DB_HOST がローカルの値'],
            'DB_HOST が未設定' => [['database.connections.mysql.host' => ''], 'DB_HOST がローカルの値'],
            'DB_PASSWORD が空（migrate が落ち続ける）' => [['database.connections.mysql.password' => ''], 'DB_PASSWORD が空'],
            'DB_USERNAME が未設定' => [['database.connections.mysql.username' => ''], 'DB_USERNAME が未設定'],
            'DB_DATABASE が未設定' => [['database.connections.mysql.database' => ''], 'DB_DATABASE が未設定'],
            'DB_PORT が数字でない（参照変数が展開されていない）' => [['database.connections.mysql.port' => '${{MySQL.MYSQLPORT}}'], 'DB_PORT が数字ではありません'],
            'CORS が空' => [['cors.allowed_origins' => []], 'CORS_ALLOWED_ORIGINS が空'],
            'CORS に開発用の既定値が残っている（変数が未設定）' => [['cors.allowed_origins' => ['http://localhost', 'http://localhost:5173']], 'ローカルの値'],
            'CORS の末尾にスラッシュ' => [['cors.allowed_origins' => ['https://courtq.vercel.app/']], '末尾にスラッシュ'],
            'CORS にワイルドカード' => [['cors.allowed_origins' => ['*']], 'ワイルドカード'],
            'CORS が http' => [['cors.allowed_origins' => ['http://courtq.vercel.app']], 'https:// で始まっていません'],
            'pusher なのにキーが空' => [['broadcasting.connections.pusher.secret' => ''], 'PUSHER_APP_ID / PUSHER_APP_KEY / PUSHER_APP_SECRET'],
            'pusher なのに cluster が未設定' => [['broadcasting.connections.pusher.options.cluster' => ''], 'PUSHER_APP_CLUSTER'],
        ];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('mistakes')]
    public function test_each_common_mistake_is_reported_as_an_error(array $overrides, string $expected): void
    {
        $this->productionConfig($overrides);

        $errors = app(DeployCheckService::class)->check()['errors'];

        $this->assertNotEmpty($errors, '間違いが検出されませんでした');
        $this->assertTrue(collect($errors)->contains(fn ($e) => str_contains($e, $expected)), "期待した指摘が無い: {$expected}\n".implode("\n", $errors));
    }

    public function test_messages_never_contain_secret_values(): void
    {
        $this->productionConfig(['app.debug' => true, 'database.connections.mysql.host' => '127.0.0.1', 'cors.allowed_origins' => ['*']]);

        $text = implode("\n", app(DeployCheckService::class)->check()['errors']);

        $this->assertStringNotContainsString('secret-password-value', $text);
        $this->assertStringNotContainsString(str_repeat('k', 32), $text);
    }

    public function test_warnings_do_not_stop_the_start(): void
    {
        $this->productionConfig([
            'queue.default' => 'sync',
            'broadcasting.default' => 'log',
            'app.frontend_url' => 'http://localhost:5173',
            'app.env' => 'staging',
        ]);

        $result = app(DeployCheckService::class)->check();

        $this->assertSame([], $result['errors']);
        $this->assertCount(4, $result['warnings']); // sync のキュー / pusher のキーがあるのに driver が違う / frontend_url / env
    }

    public function test_running_without_pusher_keys_is_allowed(): void
    {
        $this->productionConfig([
            'broadcasting.default' => 'null',
            'broadcasting.connections.pusher.key' => null,
            'broadcasting.connections.pusher.secret' => null,
            'broadcasting.connections.pusher.app_id' => null,
        ]);

        $this->assertSame(['errors' => [], 'warnings' => []], app(DeployCheckService::class)->check(), 'キー未設定でも、リアルタイムなしで動く');
    }

    public function test_wait_for_database_returns_null_when_connected(): void
    {
        $this->assertNull(app(DeployCheckService::class)->waitForDatabase(5));
    }

    public function test_wait_for_database_reports_the_failure_without_the_password(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => '1',
            'database.connections.mysql.password' => 'secret-password-value',
        ]);

        $failure = app(DeployCheckService::class)->waitForDatabase(0);

        $this->assertNotNull($failure);
        $this->assertStringNotContainsString('secret-password-value', $failure);
    }

    public function test_the_command_fails_with_japanese_guidance_and_succeeds_when_correct(): void
    {
        $this->productionConfig(['database.connections.mysql.password' => '', 'cors.allowed_origins' => ['https://courtq.vercel.app/']]);

        $this->artisan('app:deploy-check')
            ->expectsOutputToContain('[エラー] DB_PASSWORD が空です')
            ->expectsOutputToContain('[エラー] CORS_ALLOWED_ORIGINS の末尾にスラッシュ')
            ->expectsOutputToContain('2 件の設定の問題があるため、起動を中止します')
            ->assertExitCode(1);

    }

    public function test_the_command_succeeds_and_checks_the_database_connection(): void
    {
        $this->productionConfig();
        // テストの DB（sqlite）につながるか確認する。設定の検査では mysql を要求するので、ここは設定の検査を通した状態で DB だけ差し替える
        config(['database.connections.mysql' => config('database.connections.sqlite'), 'database.default' => 'mysql']);
        config(['database.connections.mysql.host' => 'mysql.railway.internal', 'database.connections.mysql.port' => '3306', 'database.connections.mysql.username' => 'root', 'database.connections.mysql.password' => 'x']);

        $this->artisan('app:deploy-check', ['--wait-db' => 3])
            ->expectsOutputToContain('DB に接続できました')
            ->expectsOutputToContain('設定の確認: 問題はありません')
            ->assertExitCode(0);
    }

    public function test_worker_check_skips_the_api_only_settings_but_still_requires_the_database_and_key(): void
    {
        $this->productionConfig([
            'app.url' => 'http://localhost',
            'cors.allowed_origins' => [],
            'database.connections.mysql.password' => '',
            'app.key' => '',
        ]);

        $errors = app(DeployCheckService::class)->check(forWorker: true)['errors'];

        $this->assertCount(2, $errors); // APP_KEY と DB_PASSWORD だけ。APP_URL・CORS は worker には関係ない
        $this->assertStringContainsString('APP_KEY', $errors[0]);
        $this->assertStringContainsString('DB_PASSWORD', $errors[1]);
    }
}
