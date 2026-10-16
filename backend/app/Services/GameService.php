<?php

namespace App\Services;

use App\Models\Game;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

class GameService
{
    /** 一覧に返す試合の最大件数（軽い応答を保つ） */
    private const LIMIT = 100;

    /**
     * 試合の一覧（GAME-01）。
     * - 今日開催の試合を先頭に、あとは開始時刻の早い順
     * - $date: その日（アプリのタイムゾーン）の試合だけ
     * - $from: その日以降の試合だけ。どちらも省略すると、今日以降の試合
     *
     * @return Collection<int, Game>
     */
    public function list(?CarbonInterface $date = null, ?CarbonInterface $from = null): Collection
    {
        $query = Game::query()->with('venue');

        if ($date !== null) {
            $query->onDate($date);
        } else {
            $query->where('tipoff_at', '>=', ($from ?? now())->copy()->startOfDay());
        }

        // CASE 式は MySQL / sqlite のどちらでも同じ意味で動く
        return $query
            ->orderByRaw('CASE WHEN tipoff_at BETWEEN ? AND ? THEN 0 ELSE 1 END', [now()->startOfDay(), now()->endOfDay()])
            ->orderBy('tipoff_at')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();
    }
}
