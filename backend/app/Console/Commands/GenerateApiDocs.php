<?php

namespace App\Console\Commands;

use App\Services\ApiDocsGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class GenerateApiDocs extends Command
{
    protected $signature = 'docs:api {--output= : 出力先（既定: リポジトリルートの docs/api-endpoints.md）}';

    protected $description = 'route:list --json と FormRequest から API 仕様書（docs/api-endpoints.md）を生成する';

    public function handle(ApiDocsGenerator $generator): int
    {
        Artisan::call('route:list', ['--json' => true, '--path' => 'api']);
        $routes = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $examples = require base_path('docs/api-examples.php');

        $path = $this->option('output') ?: base_path('../docs/api-endpoints.md');
        file_put_contents($path, $generator->generate($routes, $examples));

        $this->info("API 仕様書を生成しました: {$path}（" . count($routes) . ' ルート）');

        return self::SUCCESS;
    }
}
