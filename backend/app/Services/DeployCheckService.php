<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 本番の起動前に、環境変数（config）の取り違えを見つける。Railway の初回デプロイでよくある失敗を、
 * 再起動ループ（migrate が落ち続ける）になる前に、日本語で直し方つきで伝えるため。
 *
 * - errors: このままでは動かない・危険（起動を止める）
 * - warnings: 動くが意図と違う可能性がある（表示するだけ）
 * 秘密の値（パスワード・キー）はメッセージに含めない。
 */
class DeployCheckService
{
    /** @var array<int, string> */
    private array $errors = [];

    /** @var array<int, string> */
    private array $warnings = [];

    /**
     * @param  bool  $forWorker  true なら、API の公開に関わる項目（APP_URL・CORS）は見ない（worker は HTTP を受けないため）
     * @return array{errors: array<int, string>, warnings: array<int, string>}
     */
    public function check(bool $forWorker = false): array
    {
        $this->errors = [];
        $this->warnings = [];

        $this->checkApp($forWorker);
        $this->checkDatabase();
        if (! $forWorker) {
            $this->checkCors();
        }
        $this->checkQueueAndRealtime();

        return ['errors' => $this->errors, 'warnings' => $this->warnings];
    }

    /**
     * DB に接続できるまで待つ（Railway では MySQL が web より遅れて起動することがある）。
     *
     * @return string|null 接続できたら null、できなければ最後のエラー（パスワードは含まない）
     */
    public function waitForDatabase(int $timeoutSeconds, int $intervalSeconds = 2): ?string
    {
        $deadline = time() + $timeoutSeconds;
        $last = null;

        do {
            try {
                DB::connection()->select('select 1');

                return null;
            } catch (Throwable $e) {
                DB::purge();
                $last = $this->redact($e->getMessage());
            }
            if ($timeoutSeconds > 0) {
                sleep($intervalSeconds);
            }
        } while (time() < $deadline);

        return $last;
    }

    private function checkApp(bool $forWorker): void
    {
        $key = (string) config('app.key');
        if ($key === '') {
            $this->errors[] = 'APP_KEY が未設定です。手元で `php artisan key:generate --show` を実行して表示された値（base64: で始まる）を、Railway の APP_KEY に設定してください（値をチャットやコミットに貼らないでください）。';
        } elseif (! str_starts_with($key, 'base64:') || strlen((string) base64_decode(substr($key, 7), true)) !== 32) {
            $this->errors[] = 'APP_KEY の形式が正しくありません。`php artisan key:generate --show` で生成した値（base64: で始まる 32 バイト）をそのまま設定してください。';
        }

        if (config('app.debug')) {
            $this->errors[] = 'APP_DEBUG が true です。本番では内部の情報（接続先・設定）がエラー画面に出てしまうため、false にしてください。';
        }
        if (config('app.env') !== 'production') {
            $this->warnings[] = 'APP_ENV が production ではありません（現在: '.config('app.env').'）。Railway では production にしてください。';
        }

        if ($forWorker) {
            return;
        }

        $url = (string) config('app.url');
        if ($this->isLocalUrl($url)) {
            $this->errors[] = 'APP_URL がローカルの値（'.($url === '' ? '未設定' : $url).'）です。Railway の web サービスの公開 URL（https://〜.up.railway.app）を設定してください。';
        } elseif (! str_starts_with($url, 'https://')) {
            $this->errors[] = 'APP_URL が https:// で始まっていません（現在: '.$url.'）。';
        } elseif (str_ends_with($url, '/')) {
            $this->warnings[] = 'APP_URL の末尾に / があります。末尾のスラッシュは付けないでください。';
        }

        $frontend = (string) config('app.frontend_url');
        if ($this->isLocalUrl($frontend)) {
            $this->warnings[] = 'APP_FRONTEND_URL がローカルの値（'.$frontend.'）です。Vercel の URL（末尾スラッシュなし）を設定してください。';
        }
    }

    private function checkDatabase(): void
    {
        $default = (string) config('database.default');
        if ($default !== 'mysql') {
            $this->errors[] = 'DB_CONNECTION が mysql ではありません（現在: '.$default.'）。本番は MySQL を使います。DB_CONNECTION=mysql にしてください。';

            return;
        }

        $db = (array) config('database.connections.mysql');
        $host = (string) ($db['host'] ?? '');
        if ($host === '' || in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            $this->errors[] = 'DB_HOST がローカルの値（'.($host === '' ? '未設定' : $host).'）です。Railway の参照変数 `${{MySQL.MYSQLHOST}}` を設定してください（Railway が自動で提案する変数は、ローカル開発用の値なので「Add」しないでください）。';
        }
        if (($db['database'] ?? '') === '') {
            $this->errors[] = 'DB_DATABASE が未設定です。`${{MySQL.MYSQLDATABASE}}` を設定してください。';
        }
        if (($db['username'] ?? '') === '') {
            $this->errors[] = 'DB_USERNAME が未設定です。`${{MySQL.MYSQLUSER}}` を設定してください。';
        }
        if (($db['password'] ?? '') === '') {
            $this->errors[] = 'DB_PASSWORD が空です。空のままだと migrate が失敗し続けます。`${{MySQL.MYSQLPASSWORD}}` を設定してください（参照変数の名前の MySQL は、Railway の MySQL サービスの名前と同じにします）。';
        }
        if (! ctype_digit((string) ($db['port'] ?? ''))) {
            $this->errors[] = 'DB_PORT が数字ではありません。`${{MySQL.MYSQLPORT}}` を設定してください。';
        }
    }

    private function checkCors(): void
    {
        $origins = (array) config('cors.allowed_origins');
        $patterns = (array) config('cors.allowed_origins_patterns');

        if ($origins === [] && $patterns === []) {
            $this->errors[] = 'CORS_ALLOWED_ORIGINS が空です。Vercel の URL（例: https://courtq.vercel.app。末尾スラッシュなし）を設定してください。空のままだと、ブラウザからのすべての API 呼び出しが CORS エラーになります。';

            return;
        }

        foreach ($origins as $origin) {
            if (str_contains($origin, '*')) {
                $this->errors[] = 'CORS_ALLOWED_ORIGINS にワイルドカード（'.$origin.'）は使えません。Vercel の URL をそのまま書いてください（プレビュー URL を許可するときは CORS_ALLOWED_ORIGINS_PATTERNS に正規表現で書きます）。';
            } elseif (str_ends_with($origin, '/')) {
                $this->errors[] = 'CORS_ALLOWED_ORIGINS の末尾にスラッシュがあります（'.$origin.'）。ブラウザの Origin と一致せず、CORS エラーになります。末尾の / を外してください。';
            } elseif ($this->isLocalUrl($origin)) {
                $this->errors[] = 'CORS_ALLOWED_ORIGINS にローカルの値（'.$origin.'）が入っています。本番では Vercel の URL だけにしてください（変数が未設定のとき、開発用の既定値が使われます）。';
            } elseif (! str_starts_with($origin, 'https://')) {
                $this->errors[] = 'CORS_ALLOWED_ORIGINS が https:// で始まっていません（'.$origin.'）。';
            }
        }
    }

    private function checkQueueAndRealtime(): void
    {
        if (config('queue.default') === 'sync') {
            $this->warnings[] = 'QUEUE_CONNECTION が sync です。リアルタイム配信がリクエストの中で同期的に行われます。本番は QUEUE_CONNECTION=database にして、worker サービスを動かしてください。';
        }

        $pusher = (array) config('broadcasting.connections.pusher');
        $configured = ($pusher['key'] ?? '') !== '' && ($pusher['secret'] ?? '') !== '' && ($pusher['app_id'] ?? '') !== '';

        if (config('broadcasting.default') === 'pusher') {
            if (! $configured) {
                $this->errors[] = 'BROADCAST_DRIVER=pusher ですが、PUSHER_APP_ID / PUSHER_APP_KEY / PUSHER_APP_SECRET のどれかが空です。Pusher のダッシュボードの App Keys の値を設定してください（リアルタイムを使わないなら BROADCAST_DRIVER を外します）。';
            } elseif (($pusher['options']['cluster'] ?? '') === '') {
                $this->errors[] = 'PUSHER_APP_CLUSTER が未設定です（Pusher の App Keys の cluster。例: ap3）。';
            }
        } elseif ($configured) {
            $this->warnings[] = 'Pusher のキーは設定されていますが、BROADCAST_DRIVER が pusher ではありません（現在: '.config('broadcasting.default').'）。リアルタイムは配信されません。';
        }
    }

    private function isLocalUrl(string $url): bool
    {
        if ($url === '') {
            return true;
        }
        $host = (string) parse_url($url, PHP_URL_HOST);

        return in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true) || str_ends_with($host, '.localhost');
    }

    /** 接続エラーに含まれうる秘密（パスワード・ホスト以外の接続文字列）を伏せる */
    private function redact(string $message): string
    {
        $password = (string) config('database.connections.mysql.password');
        if ($password !== '') {
            $message = str_replace($password, '***', $message);
        }

        return mb_substr($message, 0, 300);
    }
}
