<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Booth;
use App\Models\Game;
use App\Models\GameBooth;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** 管理 API（ADMIN-01〜04 と会場）の動作。誰が呼べるかは AuthorizationTest の表で検証している */
class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    private Venue $venue;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create();
        $admin->forceFill(['role' => UserRole::Admin])->save();
        Sanctum::actingAs($admin);
        $this->venue = Venue::factory()->create(['name' => 'みなと第一アリーナ']);
    }

    /** @return array<string, mixed> */
    private function gameBody(array $override = []): array
    {
        return array_merge([
            'season' => '2026-27', 'section' => 5, 'home_team' => 'A チーム', 'away_team' => 'B チーム',
            'venue_id' => $this->venue->id, 'tipoff_at' => '2026-11-01T19:05:00+09:00', 'status' => 'scheduled',
        ], $override);
    }

    private function csv(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('games.csv', "season,section,date,tipoff_time,home_team,away_team,venue\n".$body);
    }

    // ===== 試合 =====

    public function test_create_show_update_and_delete_a_game(): void
    {
        $id = $this->postJson('/api/admin/games', $this->gameBody())
            ->assertCreated()
            ->assertJsonPath('data.home_team', 'A チーム')
            ->assertJsonPath('data.venue.name', 'みなと第一アリーナ')
            ->assertJsonPath('data.booths_count', 0)
            ->json('data.id');

        $this->getJson("/api/admin/games/{$id}")->assertOk()->assertJsonPath('data.tipoff_at', '2026-11-01T19:05:00+09:00');
        $this->putJson("/api/admin/games/{$id}", $this->gameBody(['status' => 'live', 'home_team' => '改名ホーム']))
            ->assertOk()->assertJsonPath('data.status', 'live')->assertJsonPath('data.home_team', '改名ホーム');
        $this->deleteJson("/api/admin/games/{$id}")->assertOk()->assertJson(['message' => '試合を削除しました']);
        $this->getJson("/api/admin/games/{$id}")->assertNotFound();
    }

    public function test_game_status_defaults_to_scheduled(): void
    {
        $body = $this->gameBody();
        unset($body['status']);

        $this->postJson('/api/admin/games', $body)->assertCreated()->assertJsonPath('data.status', 'scheduled');
    }

    public function test_game_validation(): void
    {
        $this->postJson('/api/admin/games', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['season', 'home_team', 'away_team', 'venue_id', 'tipoff_at']);
        $this->postJson('/api/admin/games', $this->gameBody(['away_team' => 'A チーム']))->assertUnprocessable()->assertJsonValidationErrors('away_team');
        $this->postJson('/api/admin/games', $this->gameBody(['venue_id' => 99999]))->assertUnprocessable()->assertJsonValidationErrors('venue_id');
        $this->postJson('/api/admin/games', $this->gameBody(['status' => 'cancelled']))->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson('/api/admin/games', $this->gameBody(['section' => 0]))->assertUnprocessable()->assertJsonValidationErrors('section');
        $this->postJson('/api/admin/games', $this->gameBody(['tipoff_at' => 'いつか']))->assertUnprocessable()->assertJsonValidationErrors('tipoff_at');
    }

    public function test_duplicate_game_returns_422_with_a_japanese_message(): void
    {
        $this->postJson('/api/admin/games', $this->gameBody(['section' => null]))->assertCreated();

        $this->postJson('/api/admin/games', $this->gameBody(['section' => null]))
            ->assertUnprocessable()
            ->assertJson(['message' => '同じ試合（シーズン・節・ホーム・アウェイが同じ）がすでに登録されています']);
    }

    public function test_game_with_records_cannot_be_deleted(): void
    {
        $game = Game::factory()->create();
        GameBooth::factory()->for($game)->withCongestion()->create();

        $this->deleteJson("/api/admin/games/{$game->id}")->assertUnprocessable();
        $this->assertModelExists($game);
    }

    public function test_game_list_includes_booth_counts(): void
    {
        $game = Game::factory()->create();
        GameBooth::factory()->count(2)->for($game)->create();

        $this->getJson('/api/admin/games')->assertOk()->assertJsonPath('data.0.booths_count', 2);
    }

    // ===== 会場 =====

    public function test_venues_can_be_listed_and_created_but_names_must_be_unique(): void
    {
        $this->postJson('/api/admin/venues', ['name' => '新アリーナ', 'address' => '架空市 1-2-3'])->assertCreated()->assertJsonPath('data.name', '新アリーナ');
        $this->postJson('/api/admin/venues', ['name' => '新アリーナ'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->getJson('/api/admin/venues')->assertOk()->assertJsonCount(2, 'data');
    }

    // ===== CSV =====

    public function test_csv_dry_run_previews_without_saving_and_real_import_saves(): void
    {
        $row = "2026-27,5,2026-11-01,19:05,ホーム A,アウェイ B,みなと第一アリーナ\n";

        $this->post('/api/admin/games/import', ['file' => $this->csv($row), 'dry_run' => '1'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.dry_run', true)
            ->assertJsonPath('data.created_count', 1)
            ->assertJsonPath('data.rows.0.action', 'create');
        $this->assertSame(0, Game::count());

        $this->post('/api/admin/games/import', ['file' => $this->csv($row), 'dry_run' => '0'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.created_count', 1);
        $this->assertSame(1, Game::count());

        $this->post('/api/admin/games/import', ['file' => $this->csv($row)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.created_count', 0)->assertJsonPath('data.skipped_count', 1);
        $this->assertSame(1, Game::count());
    }

    public function test_csv_row_errors_return_422_with_every_error_and_save_nothing(): void
    {
        $response = $this->post('/api/admin/games/import', ['file' => $this->csv(
            "2026-27,5,2026-11-01,19:05,ホーム A,アウェイ B,みなと第一アリーナ\n2026-27,5,きょう,19:05,ホーム C,アウェイ D,みなと第一アリーナ\n"
        )], ['Accept' => 'application/json']);

        $response->assertUnprocessable()->assertJsonValidationErrors('csv');
        $this->assertStringStartsWith('3 行目:', $response->json('errors.csv.0'));
        $this->assertSame(0, Game::count());
    }

    public function test_csv_structure_errors_return_422_with_message(): void
    {
        $this->post('/api/admin/games/import', ['file' => UploadedFile::fake()->createWithContent('g.csv', "a,b\n1,2\n")], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, '見出しが足りません'));
    }

    public function test_csv_upload_validation(): void
    {
        $accept = ['Accept' => 'application/json'];

        $this->post('/api/admin/games/import', [], $accept)->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post('/api/admin/games/import', ['file' => UploadedFile::fake()->create('virus.exe', 1)], $accept)->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post('/api/admin/games/import', ['file' => UploadedFile::fake()->create('big.csv', 2048)], $accept)->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post('/api/admin/games/import', ['file' => $this->csv(''), 'dry_run' => 'maybe'], $accept)->assertUnprocessable()->assertJsonValidationErrors('dry_run');
    }

    public function test_csv_import_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/api/admin/games/import', ['file' => $this->csv("2026-27,,2026-11-01,19:05,H{$i},A{$i},みなと第一アリーナ\n"), 'dry_run' => '1'], ['Accept' => 'application/json'])->assertOk();
        }

        $this->post('/api/admin/games/import', ['file' => $this->csv(''), 'dry_run' => '1'], ['Accept' => 'application/json'])->assertStatus(429);
    }

    // ===== ブース =====

    public function test_create_show_update_and_delete_a_booth(): void
    {
        $id = $this->postJson('/api/admin/booths', ['name' => 'ポップコーン', 'category' => 'food', 'location_label' => '1F 西'])
            ->assertCreated()->assertJsonPath('data.staff_count', 0)->assertJsonPath('data.games_count', 0)->json('data.booth_id');

        $this->getJson("/api/admin/booths/{$id}")->assertOk()->assertJsonPath('data.name', 'ポップコーン');
        $this->putJson("/api/admin/booths/{$id}", ['name' => 'キャラメルポップコーン', 'category' => 'both', 'location_label' => '1F 東', 'description' => '甘い'])
            ->assertOk()->assertJsonPath('data.category', 'both')->assertJsonPath('data.description', '甘い');
        $this->deleteJson("/api/admin/booths/{$id}")->assertOk();
        $this->getJson("/api/admin/booths/{$id}")->assertNotFound();
    }

    public function test_booth_validation_and_delete_refusal(): void
    {
        $this->postJson('/api/admin/booths', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'category', 'location_label']);
        $this->postJson('/api/admin/booths', ['name' => 'x', 'category' => 'drink', 'location_label' => 'y'])->assertUnprocessable()->assertJsonValidationErrors('category');

        $booth = Booth::factory()->create();
        GameBooth::factory()->for($booth)->create();
        $this->deleteJson("/api/admin/booths/{$booth->id}")->assertUnprocessable()->assertJsonPath('message', fn (string $m) => str_contains($m, '割り当て済み'));
    }

    // ===== 割り当て =====

    public function test_assign_booths_to_a_game_and_toggle_open(): void
    {
        $game = Game::factory()->create();
        $a = Booth::factory()->create(['name' => 'A']);
        $b = Booth::factory()->create(['name' => 'B']);

        $this->putJson("/api/admin/games/{$game->id}/booths", ['booths' => [['booth_id' => $a->id], ['booth_id' => $b->id, 'is_open' => false]]])
            ->assertOk()
            ->assertJsonPath('data.0.assigned', true)->assertJsonPath('data.0.is_open', true)
            ->assertJsonPath('data.1.assigned', true)->assertJsonPath('data.1.is_open', false);

        $this->getJson("/api/admin/games/{$game->id}/booths")->assertOk()->assertJsonCount(2, 'data');
        $this->putJson("/api/admin/games/{$game->id}/booths", ['booths' => []])->assertOk()->assertJsonPath('data.0.assigned', false);
        $this->assertSame(0, GameBooth::count());
    }

    public function test_assignment_validation(): void
    {
        $game = Game::factory()->create();
        $booth = Booth::factory()->create();

        $this->putJson("/api/admin/games/{$game->id}/booths", [])->assertUnprocessable()->assertJsonValidationErrors('booths');
        $this->putJson("/api/admin/games/{$game->id}/booths", ['booths' => [['booth_id' => 99999]]])->assertUnprocessable();
        $this->putJson("/api/admin/games/{$game->id}/booths", ['booths' => [['booth_id' => $booth->id], ['booth_id' => $booth->id]]])->assertUnprocessable();
        $this->putJson('/api/admin/games/99999/booths', ['booths' => []])->assertNotFound();
    }

    public function test_removing_a_booth_with_records_returns_422_and_keeps_the_assignment(): void
    {
        $game = Game::factory()->create();
        GameBooth::factory()->for($game)->withCongestion()->create();

        $this->putJson("/api/admin/games/{$game->id}/booths", ['booths' => []])->assertUnprocessable();
        $this->assertSame(1, GameBooth::count());
    }
}
