<?php

namespace Tests\Feature\Services;

use App\Exceptions\ServiceException;
use App\Models\Game;
use App\Models\Venue;
use App\Services\GameCsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GameCsvImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = "season,section,date,tipoff_time,home_team,away_team,venue\n";

    protected function setUp(): void
    {
        parent::setUp();
        Venue::factory()->create(['name' => 'みなと第一アリーナ']);
    }

    private function import(string $csv, bool $dryRun = false): array
    {
        return app(GameCsvImportService::class)->import($csv, $dryRun);
    }

    /** @return array<int, string> */
    private function errorsOf(string $csv): array
    {
        try {
            $this->import($csv);
        } catch (ValidationException $e) {
            return $e->errors()['csv'];
        }
        $this->fail('ValidationException が投げられませんでした');
    }

    public function test_imports_valid_rows_as_scheduled_games_in_the_app_timezone(): void
    {
        $result = $this->import(self::HEADER."2026-27,5,2026-11-01,19:05,ホーム A,アウェイ B,みなと第一アリーナ\n2026-27,,2026-11-02,14:05,ホーム C,アウェイ D,みなと第一アリーナ\n");

        $this->assertSame(2, $result['created_count']);
        $this->assertSame(0, $result['skipped_count']);
        $game = Game::where('home_team', 'ホーム A')->sole();
        $this->assertSame('scheduled', $game->status->value);
        $this->assertSame(5, $game->section);
        $this->assertSame('2026-11-01 19:05', $game->tipoff_at->timezone(config('app.timezone'))->format('Y-m-d H:i'));
        $this->assertNull(Game::where('home_team', 'ホーム C')->sole()->section, '節が空なら NULL');
    }

    public function test_dry_run_saves_nothing_but_returns_the_same_result(): void
    {
        $result = $this->import(self::HEADER."2026-27,5,2026-11-01,19:05,ホーム A,アウェイ B,みなと第一アリーナ\n", dryRun: true);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(1, $result['created_count']);
        $this->assertSame('create', $result['rows'][0]['action']);
        $this->assertSame(0, Game::count());
        $this->assertArrayNotHasKey('_data', $result['rows'][0]);
    }

    public function test_any_error_saves_nothing_and_lists_every_bad_row(): void
    {
        $errors = $this->errorsOf(self::HEADER
            ."2026-27,5,2026-11-01,19:05,ホーム A,アウェイ B,みなと第一アリーナ\n"
            ."2026-27,5,2026-13-01,19:05,ホーム C,アウェイ D,みなと第一アリーナ\n"
            ."2026-27,5,2026-11-03,25:00,ホーム E,アウェイ F,みなと第一アリーナ\n"
            ."2026-27,5,2026-11-04,19:05,ホーム G,アウェイ H,存在しない会場\n"
            ."2026-27,5,2026-11-05,19:05,同じ,同じ,みなと第一アリーナ\n");

        $this->assertSame(0, Game::count(), '1 行でも誤りがあれば、有効な行も保存しない');
        $this->assertCount(4, $errors);
        $this->assertStringStartsWith('3 行目:', $errors[0]);
        $this->assertStringStartsWith('4 行目:', $errors[1]);
        $this->assertStringContainsString('存在しない会場', $errors[2]);
        $this->assertStringStartsWith('6 行目:', $errors[3]);
    }

    public function test_impossible_dates_are_errors(): void
    {
        $errors = $this->errorsOf(self::HEADER."2026-27,5,2026-02-31,19:05,ホーム A,アウェイ B,みなと第一アリーナ\n");

        $this->assertStringStartsWith('2 行目:', $errors[0]);
    }

    public function test_already_registered_games_are_skipped_so_reimporting_does_not_duplicate(): void
    {
        $csv = self::HEADER."2026-27,5,2026-11-01,19:05,ホーム A,アウェイ B,みなと第一アリーナ\n2026-27,,2026-11-02,14:05,ホーム C,アウェイ D,みなと第一アリーナ\n";
        $this->import($csv);

        $again = $this->import($csv);

        $this->assertSame(0, $again['created_count']);
        $this->assertSame(2, $again['skipped_count']);
        $this->assertSame('skip', $again['rows'][0]['action']);
        $this->assertSame(2, Game::count(), '節が空の試合も、再取り込みで重複しない');
    }

    public function test_a_duplicate_inside_the_file_is_skipped_and_points_to_the_first_row(): void
    {
        $row = "2026-27,5,2026-11-01,19:05,ホーム A,アウェイ B,みなと第一アリーナ\n";

        $result = $this->import(self::HEADER.$row.$row);

        $this->assertSame(1, $result['created_count']);
        $this->assertSame('2 行目と同じ試合です', $result['rows'][1]['reason']);
        $this->assertSame(1, Game::count());
    }

    public function test_reads_japanese_headers_bom_crlf_and_excel_style_dates(): void
    {
        $csv = "\xEF\xBB\xBFシーズン,節,日付,開始時刻,ホーム,アウェイ,会場\r\n2026-27,5,2026/11/1,9:05,ホーム A,アウェイ B,みなと第一アリーナ\r\n";

        $this->import($csv);

        $game = Game::sole();
        $this->assertSame('2026-11-01 09:05', $game->tipoff_at->timezone(config('app.timezone'))->format('Y-m-d H:i'));
    }

    public function test_reads_shift_jis_files_exported_by_excel(): void
    {
        $utf8 = self::HEADER."2026-27,5,2026-11-01,19:05,ホーム A,アウェイ B,みなと第一アリーナ\n";
        $sjis = mb_convert_encoding($utf8, 'SJIS-win', 'UTF-8');
        $this->assertFalse(mb_check_encoding($sjis, 'UTF-8'));

        $this->import($sjis);

        $this->assertSame('みなと第一アリーナ', Game::sole()->venue->name);
    }

    public function test_quoted_fields_with_commas_are_kept(): void
    {
        $this->import(self::HEADER."2026-27,5,2026-11-01,19:05,\"ホーム, A\",アウェイ B,みなと第一アリーナ\n");

        $this->assertSame('ホーム, A', Game::sole()->home_team);
    }

    public function test_blank_lines_are_ignored_but_row_numbers_stay_true_to_the_file(): void
    {
        $errors = $this->errorsOf(self::HEADER."\n\n2026-27,5,bad,19:05,ホーム A,アウェイ B,みなと第一アリーナ\n");

        $this->assertStringStartsWith('4 行目:', $errors[0]);
    }

    public function test_missing_headers_are_reported_with_the_required_names(): void
    {
        try {
            $this->import("season,date\n2026-27,2026-11-01\n");
            $this->fail('ServiceException が投げられませんでした');
        } catch (ServiceException $e) {
            $this->assertStringContainsString('home_team', $e->getMessage());
            $this->assertSame(422, $e->status);
        }
    }

    public function test_empty_file_and_header_only_file_are_rejected(): void
    {
        foreach (['', self::HEADER, self::HEADER."\n\n"] as $csv) {
            try {
                $this->import($csv);
                $this->fail('ServiceException が投げられませんでした');
            } catch (ServiceException $e) {
                $this->assertSame(422, $e->status);
            }
        }
    }

    public function test_too_many_rows_are_rejected(): void
    {
        $rows = '';
        for ($i = 1; $i <= GameCsvImportService::MAX_ROWS + 1; $i++) {
            $rows .= "2026-27,{$i},2026-11-01,19:05,ホーム{$i},アウェイ{$i},みなと第一アリーナ\n";
        }

        $this->expectException(ServiceException::class);
        $this->import(self::HEADER.$rows);
    }

    public function test_a_failure_while_saving_rolls_back_every_row(): void
    {
        // 2 行目が保存時に失敗しても、1 行目は残らない（トランザクション）
        $csv = self::HEADER
            ."2026-27,5,2026-11-01,19:05,ホーム A,アウェイ B,みなと第一アリーナ\n"
            ."2026-27,6,2026-11-02,19:05,".str_repeat('あ', 255).",アウェイ D,みなと第一アリーナ\n";
        // 255 文字は検証を通る。DB 側で失敗させるため、先に会場を消して外部キー違反にする…のは検証と矛盾するので、ここでは保存の途中で例外を起こす
        Game::saving(function (Game $game) {
            if ($game->section === 6) {
                throw new \RuntimeException('保存に失敗');
            }
        });

        try {
            $this->import($csv);
            $this->fail('例外が投げられませんでした');
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, Game::count());
    }
}
