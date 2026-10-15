<?php

namespace Database\Seeders;

use App\Enums\BoothCategory;
use App\Enums\CongestionLevel;
use App\Enums\GameStatus;
use App\Enums\MenuItemKind;
use App\Enums\StockStatus;
use App\Enums\UserRole;
use App\Models\Booth;
use App\Models\Game;
use App\Models\GameBooth;
use App\Models\ItemStock;
use App\Models\MenuItem;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * 公開デモ用のデータ。会場・チーム・ブースはすべて架空（実在のリーグ・チームとは無関係）。
 * 冪等: 何度実行してもデータが重複しない。ただし「今日の試合」のキックオフ日は実行日に合わせ直し、
 * 今日の試合の混雑・在庫は実行時刻を基準に作り直す（デモで常に「今日」の試合が見られるようにするため）。
 *
 * アカウント（パスワードは config('demo.password')）:
 *   admin@example.com（管理者） / staff1@example.com・staff2@example.com（スタッフ） / user1@example.com・user2@example.com（一般）
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $venue = Venue::updateOrCreate(
            ['name' => 'サンプルアリーナ（架空）'],
            ['address' => '架空県架空市 1-2-3', 'map_note' => '飲食・物販ブースは 1F と 2F のコンコースにあります。階段・エレベーターは各コンコースの東西にあります。'],
        );

        $today = $this->game($venue, '北浜ウィングス', '南ヶ丘ブレイカーズ', 5, now()->setTime(19, 5), GameStatus::Live);
        $next = $this->game($venue, '南ヶ丘ブレイカーズ', '北浜ウィングス', 6, now()->addDays(7)->setTime(14, 5), GameStatus::Scheduled);

        $booths = $this->booths();
        foreach ([$today, $next] as $game) {
            foreach ($booths as $booth) {
                // 閉店中のブースは今日の試合だけ（もう一方の試合は通常営業）
                $isOpen = ! ($game->is($today) && $booth->name === 'ウィングス・グッズ西');
                GameBooth::firstOrCreate(['game_id' => $game->id, 'booth_id' => $booth->id], ['is_open' => $isOpen]);
            }
        }

        $users = $this->users($booths);
        $this->congestion($today, $booths, $users['staff1']);
        $this->stocks($today, $booths, $users['staff1'], $users['staff2']);
    }

    private function game(Venue $venue, string $home, string $away, int $section, \DateTimeInterface $tipoff, GameStatus $status): Game
    {
        return Game::updateOrCreate(
            ['season' => '2026-27', 'section' => $section, 'home_team' => $home, 'away_team' => $away],
            ['venue_id' => $venue->id, 'tipoff_at' => $tipoff, 'status' => $status],
        );
    }

    /** @return array<string, Booth> 名前をキーにしたブース */
    private function booths(): array
    {
        $definitions = [
            ['ファーストブレイク・バーガー', BoothCategory::Food, '1F コンコース東', 'ハンバーガーとポテトの店。', [
                ['ダブルチーズバーガー', MenuItemKind::Food, 850], ['ベーコンバーガー', MenuItemKind::Food, 780], ['フライドポテト', MenuItemKind::Food, 450], ['チキンナゲット（6個）', MenuItemKind::Food, 520],
            ]],
            ['ベースライン・ラーメン', BoothCategory::Food, '1F コンコース西', '醤油と味噌の二本立て。', [
                ['醤油ラーメン', MenuItemKind::Food, 900], ['味噌ラーメン', MenuItemKind::Food, 950], ['ミニ餃子（4個）', MenuItemKind::Food, 400],
            ]],
            ['ダンク・ポップコーン', BoothCategory::Food, '2F コンコース東', '塩・キャラメル・チーズの 3 種。', [
                ['塩ポップコーン', MenuItemKind::Food, 500], ['キャラメルポップコーン', MenuItemKind::Food, 600], ['チーズポップコーン', MenuItemKind::Food, 600],
            ]],
            ['ハーフタイム・ドリンク', BoothCategory::Food, '2F コンコース西', '飲み物だけを手早く。', [
                ['コーラ', MenuItemKind::Food, 400], ['ビール', MenuItemKind::Food, 700], ['ウーロン茶', MenuItemKind::Food, 350], ['ホットコーヒー', MenuItemKind::Food, 400],
            ]],
            ['フリースロー・カレー', BoothCategory::Food, '1F コンコース北', 'ビーフとチキンのカレー。', [
                ['ビーフカレー', MenuItemKind::Food, 950], ['チキンカレー', MenuItemKind::Food, 900], ['カレーパン', MenuItemKind::Food, 450],
            ]],
            ['ウィングス・グッズ東', BoothCategory::Merch, '2F コンコース東', 'ホームチームの公式グッズ（東）。', [
                ['レプリカユニフォーム', MenuItemKind::Merch, 9800], ['タオル', MenuItemKind::Merch, 1800], ['キーホルダー', MenuItemKind::Merch, 900],
            ]],
            ['ウィングス・グッズ西', BoothCategory::Merch, '2F コンコース西', 'ホームチームの公式グッズ（西）。', [
                ['Tシャツ', MenuItemKind::Merch, 4500], ['キャップ', MenuItemKind::Merch, 3200],
            ]],
            ['ファンショップ 2F', BoothCategory::Both, '2F コンコース中央', '軽食とグッズの両方を扱う売店。', [
                ['サンドイッチ', MenuItemKind::Food, 600], ['ミニバスケットボール', MenuItemKind::Merch, 2500], ['ステッカー', MenuItemKind::Merch, 500],
            ]],
        ];

        $booths = [];
        foreach ($definitions as [$name, $category, $location, $description, $items]) {
            $booth = Booth::updateOrCreate(['name' => $name], ['category' => $category, 'location_label' => $location, 'description' => $description]);
            foreach ($items as [$itemName, $kind, $price]) {
                MenuItem::updateOrCreate(['booth_id' => $booth->id, 'name' => $itemName], ['kind' => $kind, 'price' => $price, 'is_active' => true]);
            }
            $booths[$name] = $booth;
        }

        return $booths;
    }

    /**
     * @param  array<string, Booth>  $booths
     * @return array<string, User>
     */
    private function users(array $booths): array
    {
        $users = [
            'admin' => $this->user('管理者 アキラ', 'admin@example.com', UserRole::Admin),
            'staff1' => $this->user('スタッフ サクラ', 'staff1@example.com', UserRole::Staff),
            'staff2' => $this->user('スタッフ ケンタ', 'staff2@example.com', UserRole::Staff),
            'user1' => $this->user('来場者 ミドリ', 'user1@example.com', UserRole::User),
            'user2' => $this->user('来場者 ソウタ', 'user2@example.com', UserRole::User),
        ];

        // staff1: ハンバーガーとラーメン / staff2: グッズ東
        $users['staff1']->booths()->syncWithoutDetaching([$booths['ファーストブレイク・バーガー']->id, $booths['ベースライン・ラーメン']->id]);
        $users['staff2']->booths()->syncWithoutDetaching([$booths['ウィングス・グッズ東']->id]);

        // user1 のお気に入り
        $users['user1']->favoriteBooths()->syncWithoutDetaching([$booths['ダンク・ポップコーン']->id]);

        return $users;
    }

    private function user(string $name, string $email, UserRole $role): User
    {
        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'role' => $role,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);
        // 既存ユーザーのパスワードは上書きしない（デモ環境でパスワードを変えた場合を壊さない）
        if (! $user->exists) {
            $user->password = Hash::make((string) config('demo.password'));
        }
        $user->save();

        return $user;
    }

    /**
     * 今日の試合の混雑。実行時刻を基準に作り直す（履歴も含めて入れ替える）。
     *
     * @param  array<string, Booth>  $booths
     */
    private function congestion(Game $game, array $booths, User $updater): void
    {
        // [ブース名 => [レベル, 待ち分, 何分前の更新か, 履歴（何分前 => [レベル, 待ち分]）]]
        $states = [
            'ファーストブレイク・バーガー' => [CongestionLevel::Crowded, 15, 2, [40 => [2, 5], 25 => [3, 10], 10 => [3, 12]]],
            'ベースライン・ラーメン' => [CongestionLevel::Slightly, 5, 4, [30 => [1, 0], 12 => [2, 5]]],
            'ダンク・ポップコーン' => [CongestionLevel::Empty, 0, 1, [20 => [1, 0]]],
            'ハーフタイム・ドリンク' => [CongestionLevel::VeryCrowded, 30, 3, [35 => [3, 15], 18 => [4, 25], 8 => [4, 30]]],
            // 20 分前から更新が止まっている（「情報が古い可能性があります」の表示確認用）
            'フリースロー・カレー' => [CongestionLevel::Slightly, 8, 20, [20 => [2, 8]]],
            'ウィングス・グッズ東' => [CongestionLevel::Empty, 0, 6, [15 => [1, 0]]],
            // ウィングス・グッズ西 は閉店中、ファンショップ 2F はまだ誰も更新していない（混雑は null）
        ];

        foreach ($states as $name => [$level, $wait, $minutesAgo, $history]) {
            $gameBooth = GameBooth::where('game_id', $game->id)->where('booth_id', $booths[$name]->id)->firstOrFail();
            $gameBooth->logs()->delete();
            foreach ($history as $ago => [$historyLevel, $historyWait]) {
                $gameBooth->logs()->create(['level' => $historyLevel, 'wait_minutes' => $historyWait, 'user_id' => $updater->id])
                    ->forceFill(['created_at' => now()->subMinutes($ago)])->save();
            }
            $gameBooth->forceFill([
                'congestion_level' => $level,
                'wait_minutes' => $wait,
                'congestion_updated_at' => now()->subMinutes($minutesAgo),
                'congestion_updated_by' => $updater->id,
            ])->save();
        }
    }

    /**
     * 今日の試合の在庫（メニュー全品に在庫行を作り、一部を「残りわずか」「売り切れ」にする）。
     *
     * @param  array<string, Booth>  $booths
     */
    private function stocks(Game $game, array $booths, User $staff1, User $staff2): void
    {
        $special = [
            'ダブルチーズバーガー' => [StockStatus::Low, 8],
            'チキンナゲット（6個）' => [StockStatus::SoldOut, 0],
            '味噌ラーメン' => [StockStatus::Low, 5],
            'キャラメルポップコーン' => [StockStatus::SoldOut, 0],
            'ビール' => [StockStatus::Low, null],
            'ビーフカレー' => [StockStatus::SoldOut, 0],
            'レプリカユニフォーム' => [StockStatus::Low, 3],
        ];

        foreach ($booths as $booth) {
            foreach ($booth->menuItems as $item) {
                [$status, $quantity] = $special[$item->name] ?? [StockStatus::InStock, null];
                $stock = ItemStock::updateOrCreate(
                    ['game_id' => $game->id, 'menu_item_id' => $item->id],
                    ['status' => $status, 'quantity' => $quantity, 'updated_by' => $booth->name === 'ウィングス・グッズ東' ? $staff2->id : $staff1->id],
                );
                $stock->touch(); // 最終更新を実行時刻に揃える
                if ($stock->logs()->doesntExist()) {
                    $stock->logs()->create(['status' => $status, 'quantity' => $quantity, 'user_id' => $stock->updated_by]);
                }
            }
        }
    }
}
