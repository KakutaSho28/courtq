<?php

namespace App\Services;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Stringable;
use Throwable;

/**
 * `php artisan route:list --json` の出力と FormRequest のバリデーションルールから API 仕様書（Markdown）を生成する。
 * ルート・パス・認証・バリデーションはコードから取り、説明とリクエスト/レスポンス例は docs/api-examples.php から取る。
 * 出力は決定的（時刻などを含めない）なので、リポジトリのファイルと比較して古さを検知できる。
 */
class ApiDocsGenerator
{
    /**
     * @param  array<int, array<string, mixed>>  $routes  route:list --json の配列
     * @param  array<string, array<string, mixed>>  $examples  docs/api-examples.php
     */
    public function generate(array $routes, array $examples): string
    {
        $endpoints = $this->endpoints($routes, $examples);

        $out = [];
        $out[] = '# API 仕様';
        $out[] = '';
        $out[] = '> このファイルは自動生成です。直接編集せず、`backend/docs/api-examples.php` を更新して `cd backend && php artisan docs:api` を実行してください。';
        $out[] = '> ルート・パス・認証・バリデーションルールは `php artisan route:list --json` と FormRequest から取得しており、`ApiDocsTest` がコードとのずれを検知します。';
        $out[] = '';
        $out[] = $this->overview();
        $out[] = $this->index($endpoints);

        foreach ($endpoints as $endpoint) {
            $out[] = $this->section($endpoint);
        }

        $out[] = $this->realtimeSection();

        return rtrim(implode("\n", $out)) . "\n";
    }

    /**
     * ドキュメントのキー（例: "GET|POST /api/broadcasting/auth"）を、ルート定義から作る。
     *
     * @param  array<string, mixed>  $route
     */
    public function keyFor(array $route): string
    {
        $methods = array_values(array_filter(explode('|', $route['method']), fn ($m) => $m !== 'HEAD'));

        return implode('|', $methods) . ' /' . $route['uri'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $routes
     * @param  array<string, array<string, mixed>>  $examples
     * @return array<int, array<string, mixed>>
     */
    private function endpoints(array $routes, array $examples): array
    {
        $groupOrder = array_values(array_unique(array_column($examples, 'group')));

        $endpoints = [];
        foreach ($routes as $route) {
            $key = $this->keyFor($route);
            $example = $examples[$key] ?? ['group' => 'その他', 'summary' => '（説明なし）', 'responses' => []];
            [$method, $uri] = explode(' ', $key, 2);

            $endpoints[] = $example + [
                'key' => $key,
                'method' => $method,
                'uri' => $uri,
                'action' => $route['action'],
                'auth' => $this->requiresAuth($route),
                'rules' => $this->rulesFor((string) $route['action']),
                'order' => array_search($key, array_keys($examples), true),
            ];
        }

        // ドキュメントの並び: グループ順 → examples ファイル内の順
        usort($endpoints, fn ($a, $b) => [array_search($a['group'], $groupOrder, true), $a['order']] <=> [array_search($b['group'], $groupOrder, true), $b['order']]);

        return $endpoints;
    }

    /** @param array<string, mixed> $route */
    private function requiresAuth(array $route): bool
    {
        foreach ($route['middleware'] ?? [] as $middleware) {
            if (str_contains((string) $middleware, 'Authenticate:sanctum') || str_contains((string) $middleware, 'auth:sanctum')) {
                return true;
            }
        }

        return false;
    }

    /**
     * コントローラのメソッドが受け取る FormRequest のバリデーションルールを取り出す。
     *
     * @return array<string, array<int, string>>
     */
    private function rulesFor(string $action): array
    {
        $class = $action;
        $method = '__invoke';
        if (str_contains($action, '@')) {
            [$class, $method] = explode('@', $action, 2);
        }
        if (! class_exists($class) || ! method_exists($class, $method)) {
            return [];
        }

        foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            if (is_subclass_of($type->getName(), FormRequest::class)) {
                try {
                    $request = (new ReflectionClass($type->getName()))->newInstanceWithoutConstructor();
                    $rules = [];
                    foreach ($request->rules() as $field => $fieldRules) {
                        $rules[$field] = array_map(fn ($rule) => $this->stringifyRule($rule), (array) $fieldRules);
                    }

                    return $rules;
                } catch (Throwable) {
                    return [];
                }
            }
        }

        return [];
    }

    private function stringifyRule(mixed $rule): string
    {
        return match (true) {
            is_string($rule) => $rule,
            $rule instanceof Closure => 'カスタム検証',
            $rule instanceof Stringable => (string) $rule,
            is_object($rule) => (new ReflectionClass($rule))->getShortName(),
            default => (string) $rule,
        };
    }

    private function overview(): string
    {
        return <<<'MD'
## 共通仕様

- ベース URL: `https://<railway-domain>/api`（ローカル: `http://localhost/api`）
- 形式: JSON（`Accept: application/json`）。アップロードのみ `multipart/form-data`
- 認証: Laravel Sanctum の Bearer トークン。`POST /api/auth/register` または `POST /api/auth/login` で取得し、`Authorization: Bearer <token>` を付ける。「認証: 必要」のエンドポイントで未指定・無効なら 401
- レスポンスの形式: 成功は `{ "data": ... }`、メッセージのみは `{ "message": "..." }`。一覧（ページング）は `{ "data": [...], "meta": {...}, "links": {...} }`
- 日時: ISO 8601（例 `2026-10-10T10:00:00+09:00`）
- ページング: 1 ページ 20 件。`?page=` で指定

### 共通エラー

| ステータス | 意味 | 形式 |
|---|---|---|
| 401 | 未認証 | `{ "message": "Unauthenticated." }` |
| 403 | 権限なし | `{ "message": "この操作は許可されていません" }` |
| 404 | 対象が存在しない | `{ "message": "..." }` |
| 422 | 入力エラー | `{ "message": "...", "errors": { "<field>": ["..."] } }` |
| 429 | リクエスト過多（スロットリング） | `{ "message": "Too Many Attempts." }` |

### 権限の考え方

| 対象 | 閲覧・アノテーション・コメント・共有・切り抜き | 削除 |
|---|---|---|
| 個人動画 | 投稿者のみ | 投稿者のみ |
| チーム動画 | チームメンバー全員 | 投稿者、またはチームオーナー |
| コメント | 動画を閲覧できる人 | 投稿者本人のみ |
| チーム | メンバー全員が閲覧 | オーナーのみ（メンバーの削除はオーナー、脱退は本人） |

MD;
    }

    /** @param array<int, array<string, mixed>> $endpoints */
    private function index(array $endpoints): string
    {
        $out = ['## エンドポイント一覧', ''];
        $group = null;
        foreach ($endpoints as $i => $e) {
            if ($e['group'] !== $group) {
                $group = $e['group'];
                $out[] = "### {$group}";
                $out[] = '';
                $out[] = '| メソッド | パス | 認証 | 概要 |';
                $out[] = '|---|---|---|---|';
            }
            $out[] = sprintf('| %s | `%s` | %s | %s |', $this->cell($e['method']), $e['uri'], $e['auth'] ? '必要' : '不要', $this->cell($e['summary']));
            if (($endpoints[$i + 1]['group'] ?? null) !== $group) {
                $out[] = '';
            }
        }

        return implode("\n", $out);
    }

    /** @param array<string, mixed> $e */
    private function section(array $e): string
    {
        $out = ["---", '', "## {$e['method']} `{$e['uri']}`", '', "**{$e['summary']}**", ''];
        $out[] = sprintf('- 実装: `%s`', $e['action']);
        $out[] = '- 認証: ' . ($e['auth'] ? '必要（Bearer トークン）' : '不要');
        $out[] = '';

        if (! empty($e['description'])) {
            $out[] = $e['description'];
            $out[] = '';
        }

        preg_match_all('/\{(\w+)\}/', $e['uri'], $params);
        if ($params[1]) {
            $out[] = '**パスパラメータ**: ' . implode('、', array_map(fn ($p) => "`{$p}`", $params[1]));
            $out[] = '';
        }

        if (! empty($e['query'])) {
            $out[] = '**クエリパラメータ**';
            $out[] = '';
            $out[] = '| 名前 | 説明 |';
            $out[] = '|---|---|';
            foreach ($e['query'] as $name => $description) {
                $out[] = "| `{$name}` | " . $this->cell($description) . ' |';
            }
            $out[] = '';
        }

        if ($e['rules']) {
            $out[] = '**リクエストのバリデーション**（FormRequest から自動取得）';
            $out[] = '';
            $out[] = '| フィールド | ルール |';
            $out[] = '|---|---|';
            foreach ($e['rules'] as $field => $rules) {
                $out[] = "| `{$field}` | `" . implode('` `', array_map(fn ($rule) => $this->cell($rule), $rules)) . '` |';
            }
            $out[] = '';
        }

        if (array_key_exists('request', $e) && $e['request'] !== null) {
            $out[] = '**リクエスト例**';
            $out[] = '';
            $out[] = $this->json($e['request']);
            $out[] = '';
        }

        if (! empty($e['responses'])) {
            $out[] = '**レスポンス**';
            $out[] = '';
            foreach ($e['responses'] as $status => $response) {
                $out[] = "- `{$status}` {$response['description']}";
                if (! empty($response['example'])) {
                    $out[] = '';
                    $out[] = $this->json($response['example']);
                    $out[] = '';
                }
            }
            $out[] = '';
        }

        return implode("\n", $out);
    }

    private function realtimeSection(): string
    {
        return <<<'MD'
---

## リアルタイムイベント（Pusher Channels）

REST ではなく WebSocket で配信される通知。試合（ゲーム）ごとに 1 つの**公開チャンネル** `game.{gameId}` を購読する（内容が機微でなく、閲覧者ごとの認証の負荷を避けるため。チャンネル認証 API は無い）。イベントはキュー経由で worker が送信する。

> イベントの一覧（混雑の更新・在庫の更新）は WP-B8 で実装し、この節に追記する。
MD;
    }

    /** Markdown の表のセル内で `|` が列区切りと解釈されないようにする（コードスパン内も `\|` で表示される） */
    private function cell(string $text): string
    {
        return str_replace('|', '\\|', $text);
    }

    private function json(mixed $value): string
    {
        return "```json\n" . json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n```";
    }
}
