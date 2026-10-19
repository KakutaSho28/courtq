<?php

namespace App\Services;

use App\Exceptions\ServiceException;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * 試合の CSV 取り込み（ADMIN-02）。
 *
 * - 1 行目は見出し。`season,section,date,tipoff_time,home_team,away_team,venue`（日本語の見出し `シーズン,節,日付,開始時刻,ホーム,アウェイ,会場` も可）
 * - 文字コードは UTF-8（BOM あり/なし）と Shift_JIS（Excel の「CSV」）を自動で判定する
 * - **1 行でも誤りがあれば、何も保存しない**（途中まで入ると、どこまで入ったか分からなくなるため）
 * - すでに登録済みの試合（シーズン・節・ホーム・アウェイが同じ）は、取り込まずにスキップ（再取り込みしても重複しない）
 * - 会場は名前の完全一致で照合する。無い会場は誤りにする（打ち間違いで会場が増えるのを防ぐ）
 * - `$dryRun` なら、保存せずに結果（登録される行・スキップされる行）だけを返す
 */
class GameCsvImportService
{
    public const MAX_ROWS = 500;

    private const HEADERS = [
        'season' => ['season', 'シーズン'],
        'section' => ['section', '節'],
        'date' => ['date', '日付'],
        'tipoff_time' => ['tipoff_time', '開始時刻'],
        'home_team' => ['home_team', 'ホーム'],
        'away_team' => ['away_team', 'アウェイ'],
        'venue' => ['venue', '会場'],
    ];

    public function __construct(private readonly AdminGameService $games) {}

    /**
     * @return array{dry_run: bool, created_count: int, skipped_count: int, rows: array<int, array<string, mixed>>}
     *
     * @throws ValidationException 誤りのある行があるとき（errors.csv に「N 行目: ...」を入れる）
     */
    public function import(string $content, bool $dryRun = false): array
    {
        $table = $this->parse($this->toUtf8($content));
        $venues = Venue::query()->pluck('id', 'name');

        $rows = [];
        $errors = [];
        $seen = [];

        foreach ($table as $line => $record) {
            $parsed = $this->validateRow($record, $line, $venues->all(), $errors);
            if ($parsed === null) {
                continue;
            }

            $key = implode('|', [$parsed['season'], $parsed['section'] ?? '-', $parsed['home_team'], $parsed['away_team']]);
            $skip = null;
            if (isset($seen[$key])) {
                $skip = "{$seen[$key]} 行目と同じ試合です";
            } elseif ($this->games->findDuplicate($parsed['season'], $parsed['section'], $parsed['home_team'], $parsed['away_team']) !== null) {
                $skip = 'すでに登録されています';
            }
            $seen[$key] ??= $line;

            $rows[] = [
                'row' => $line,
                'action' => $skip === null ? 'create' : 'skip',
                'reason' => $skip,
                'season' => $parsed['season'],
                'section' => $parsed['section'],
                'home_team' => $parsed['home_team'],
                'away_team' => $parsed['away_team'],
                'venue' => $parsed['venue_name'],
                'tipoff_at' => $parsed['tipoff_at']->toIso8601String(),
                '_data' => $parsed,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['csv' => $errors]);
        }

        $created = array_values(array_filter($rows, fn (array $r) => $r['action'] === 'create'));
        if (! $dryRun && $created !== []) {
            DB::transaction(function () use ($created) {
                foreach ($created as $row) {
                    $this->games->create([
                        'season' => $row['_data']['season'],
                        'section' => $row['_data']['section'],
                        'home_team' => $row['_data']['home_team'],
                        'away_team' => $row['_data']['away_team'],
                        'venue_id' => $row['_data']['venue_id'],
                        'tipoff_at' => $row['_data']['tipoff_at'],
                        'status' => 'scheduled',
                    ]);
                }
            });
        }

        return [
            'dry_run' => $dryRun,
            'created_count' => count($created),
            'skipped_count' => count($rows) - count($created),
            'rows' => array_map(fn (array $r) => array_diff_key($r, ['_data' => true]), $rows),
        ];
    }

    /** UTF-8 に揃える（BOM を取り除く。UTF-8 でなければ Excel の Shift_JIS とみなす） */
    private function toUtf8(string $content): string
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'SJIS-win');
        }

        return $content;
    }

    /**
     * 見出しを項目名に対応づけて、行ごとの連想配列にする。キーは CSV の行番号（見出しが 1 行目なのでデータは 2 行目から）。
     *
     * @return array<int, array<string, string>>
     */
    private function parse(string $content): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $headerRow = fgetcsv($stream);
        if ($headerRow === false || $headerRow === [null]) {
            fclose($stream);
            throw new ServiceException('CSV が空です', 422);
        }

        $columns = [];
        foreach ($headerRow as $index => $name) {
            $name = trim((string) $name);
            foreach (self::HEADERS as $field => $aliases) {
                if (in_array($name, $aliases, true)) {
                    $columns[$field] = $index;
                }
            }
        }
        $missing = array_diff(array_keys(self::HEADERS), array_keys($columns));
        if ($missing !== []) {
            fclose($stream);
            throw new ServiceException('1 行目の見出しが足りません: '.implode(', ', $missing).'（必要な見出し: '.implode(',', array_keys(self::HEADERS)).'）', 422);
        }

        $table = [];
        $line = 1;
        while (($record = fgetcsv($stream)) !== false) {
            $line++;
            if ($record === [null] || implode('', array_map('trim', array_map('strval', $record))) === '') {
                continue; // 空行は読み飛ばす
            }
            if (count($table) >= self::MAX_ROWS) {
                fclose($stream);
                throw new ServiceException('一度に取り込めるのは '.self::MAX_ROWS.' 行までです', 422);
            }
            $table[$line] = array_map(fn (int $i) => trim((string) ($record[$i] ?? '')), $columns);
        }
        fclose($stream);

        if ($table === []) {
            throw new ServiceException('取り込む行がありません', 422);
        }

        return $table;
    }

    /**
     * 1 行を検証して、保存用の値にする。誤りがあれば $errors に「N 行目: ...」を足して null を返す。
     *
     * @param  array<string, string>  $record
     * @param  array<string, int>  $venues  会場名 => id
     * @param  array<int, string>  $errors
     * @return array<string, mixed>|null
     */
    private function validateRow(array $record, int $line, array $venues, array &$errors): ?array
    {
        // Excel が 2026/10/6 や 9:05 の形で書き出しても読めるよう、ゼロ埋めの 2026-10-06 / 09:05 に揃える
        $record['date'] = preg_replace_callback('#^(\d{4})[-/](\d{1,2})[-/](\d{1,2})$#', fn ($m) => sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]), $record['date']) ?? $record['date'];
        $record['tipoff_time'] = preg_replace_callback('#^(\d{1,2}):(\d{2})$#', fn ($m) => sprintf('%02d:%02d', $m[1], $m[2]), $record['tipoff_time']) ?? $record['tipoff_time'];

        $validator = Validator::make($record, [
            'season' => ['required', 'string', 'max:20'],
            'section' => ['nullable', 'integer', 'min:1', 'max:999'],
            'date' => ['required', 'date_format:Y-m-d'],
            'tipoff_time' => ['required', 'date_format:H:i'],
            'home_team' => ['required', 'string', 'max:255'],
            'away_team' => ['required', 'string', 'max:255', 'different:home_team'],
            'venue' => ['required', 'string'],
        ], [], [
            'season' => 'season（シーズン）', 'section' => 'section（節）', 'date' => 'date（日付）', 'tipoff_time' => 'tipoff_time（開始時刻）',
            'home_team' => 'home_team（ホーム）', 'away_team' => 'away_team（アウェイ）', 'venue' => 'venue（会場）',
        ]);

        $problems = $validator->errors()->all();
        if (! $validator->fails() && ! isset($venues[$record['venue']])) {
            $problems[] = "会場「{$record['venue']}」が登録されていません（会場名は完全に一致させてください）";
        }
        if ($problems !== []) {
            foreach ($problems as $problem) {
                $errors[] = "{$line} 行目: {$problem}";
            }

            return null;
        }

        return [
            'season' => $record['season'],
            'section' => $record['section'] === '' ? null : (int) $record['section'],
            'home_team' => $record['home_team'],
            'away_team' => $record['away_team'],
            'venue_id' => $venues[$record['venue']],
            'venue_name' => $record['venue'],
            'tipoff_at' => Carbon::createFromFormat('Y-m-d H:i', "{$record['date']} {$record['tipoff_time']}", config('app.timezone')),
        ];
    }
}
