<?php

namespace App\Services;

use App\Exceptions\ServiceException;
use App\Models\Game;
use App\Models\ItemStock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;

/** 管理者による試合の登録・編集・削除（ADMIN-01）。誰が操作してよいかは、ルートの role:admin で確認済みの前提 */
class AdminGameService
{
    /** 一覧に返す試合の最大件数 */
    private const LIMIT = 200;

    /** クライアントから変更を受け付ける項目 */
    private const EDITABLE = ['season', 'section', 'home_team', 'away_team', 'venue_id', 'tipoff_at', 'status'];

    /**
     * 試合の一覧。今日以降の試合を開始が早い順で先に、過去の試合はその後ろに新しい順で並べる
     * （管理画面でこれから使う試合がすぐ見つかるように）。
     *
     * @return Collection<int, Game>
     */
    public function list(): Collection
    {
        $startOfToday = now()->startOfDay();

        return Game::query()
            ->with('venue')
            ->withCount('gameBooths')
            ->orderByRaw('CASE WHEN tipoff_at >= ? THEN 0 ELSE 1 END', [$startOfToday])
            ->orderByRaw('CASE WHEN tipoff_at >= ? THEN tipoff_at END ASC', [$startOfToday])
            ->orderByDesc('tipoff_at')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): Game
    {
        $data = Arr::only($data, self::EDITABLE);
        $this->assertNotDuplicate($data);

        return Game::create($data)->refresh()->load('venue')->loadCount('gameBooths');
    }

    /** @param array<string, mixed> $data */
    public function update(Game $game, array $data): Game
    {
        $data = Arr::only($data, self::EDITABLE);
        $this->assertNotDuplicate($data, $game);
        $game->update($data);

        return $game->refresh()->load('venue')->loadCount('gameBooths');
    }

    /**
     * 混雑・在庫の記録がまだ無い試合だけ削除できる。削除すると、出店ブースと混雑・在庫の履歴も消えてしまうため、
     * 記録がある試合は「終了」にしてもらう。
     */
    public function delete(Game $game): void
    {
        if ($this->hasRecords($game)) {
            throw new ServiceException('混雑・在庫の記録がある試合は削除できません。終了した試合は、状態を「終了」にしてください', 422);
        }

        $game->delete();
    }

    /** 同じ試合（シーズン・節・ホーム・アウェイ）がすでにあるか。節が空（NULL）の試合は、DB の一意制約では防げないのでここで確かめる */
    public function findDuplicate(string $season, ?int $section, string $home, string $away, ?Game $except = null): ?Game
    {
        return Game::query()
            ->where('season', $season)
            ->where('home_team', $home)
            ->where('away_team', $away)
            ->when($section === null, fn ($q) => $q->whereNull('section'), fn ($q) => $q->where('section', $section))
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except->id))
            ->first();
    }

    /** @param array<string, mixed> $data */
    private function assertNotDuplicate(array $data, ?Game $except = null): void
    {
        $merged = $except === null ? $data : array_merge($except->only(['season', 'section', 'home_team', 'away_team']), $data);

        if ($this->findDuplicate($merged['season'], $merged['section'] ?? null, $merged['home_team'], $merged['away_team'], $except) !== null) {
            throw new ServiceException('同じ試合（シーズン・節・ホーム・アウェイが同じ）がすでに登録されています', 422);
        }
    }

    private function hasRecords(Game $game): bool
    {
        return $game->gameBooths()->whereNotNull('congestion_updated_at')->exists()
            || ItemStock::query()->where('game_id', $game->id)->exists();
    }
}
