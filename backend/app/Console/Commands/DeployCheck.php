<?php

namespace App\Console\Commands;

use App\Services\DeployCheckService;
use Illuminate\Console\Command;

class DeployCheck extends Command
{
    protected $signature = 'app:deploy-check {--wait-db=0 : DB に接続できるまで待つ秒数（0 なら待たない）} {--worker : worker 用（APP_URL・CORS は確認しない）}';

    protected $description = '本番の起動前に、環境変数（APP_KEY・DB・CORS・Pusher など）の取り違えを日本語で知らせる';

    public function handle(DeployCheckService $service): int
    {
        $result = $service->check((bool) $this->option('worker'));

        foreach ($result['warnings'] as $warning) {
            $this->warn("[警告] {$warning}");
        }
        foreach ($result['errors'] as $error) {
            $this->error("[エラー] {$error}");
        }

        if ($result['errors'] !== []) {
            $this->line('');
            $this->error(count($result['errors']).' 件の設定の問題があるため、起動を中止します。docs/deployment.md の環境変数の表を確認してください。');

            return self::FAILURE;
        }

        $wait = (int) $this->option('wait-db');
        if ($wait > 0) {
            $this->info("DB への接続を確認しています（最大 {$wait} 秒）...");
            $failure = $service->waitForDatabase($wait);
            if ($failure !== null) {
                $this->error("[エラー] DB に接続できません: {$failure}");
                $this->error('MySQL サービスが起動しているか、DB_HOST / DB_PORT / DB_USERNAME / DB_PASSWORD の参照変数が正しいか確認してください。');

                return self::FAILURE;
            }
            $this->info('DB に接続できました。');
        }

        $this->info('設定の確認: 問題はありません。');

        return self::SUCCESS;
    }
}
