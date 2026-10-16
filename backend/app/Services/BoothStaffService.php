<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\ServiceException;
use App\Models\Booth;
use App\Models\GameBooth;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** スタッフと担当ブースの関係（STAFF-01、ADMIN-06） */
class BoothStaffService
{
    /** 担当ブースごとに返す、今日以降の試合の数（スタッフ画面で「今日の試合」をすぐ開けるように） */
    private const UPCOMING_GAMES = 3;

    /**
     * 管理できるブース（STAFF-01）。admin は全ブース、staff は担当ブースだけ。
     * 各ブースに、今日以降の出店予定（game_booths + game）を開始時刻の早い順で最大 3 件付ける。
     *
     * @return Collection<int, Booth>
     */
    public function manageableBooths(User $user): Collection
    {
        $booths = $user->isAdmin()
            ? Booth::query()->orderBy('name')->get()
            : $user->booths()->orderBy('booths.name')->get();

        $upcoming = GameBooth::query()
            ->with('game')
            ->whereIn('booth_id', $booths->modelKeys())
            ->whereHas('game', fn ($q) => $q->where('tipoff_at', '>=', now()->startOfDay()))
            ->get()
            ->sortBy(fn (GameBooth $gb) => $gb->game->tipoff_at->getTimestamp())
            ->groupBy('booth_id');

        foreach ($booths as $booth) {
            $booth->setRelation('upcomingGameBooths', ($upcoming->get($booth->id) ?? collect())->take(self::UPCOMING_GAMES)->values());
        }

        return $booths;
    }

    /**
     * ブースの担当スタッフ（ADMIN-06）
     *
     * @return Collection<int, User>
     */
    public function staffOf(Booth $booth): Collection
    {
        return $booth->staff()->orderBy('users.name')->get();
    }

    /**
     * 担当から外す（ADMIN-06）。担当ブースが 0 件になった staff は user に戻す（使わない権限を残さない）。
     * admin の役割は変えない。
     */
    public function remove(Booth $booth, User $user): void
    {
        DB::transaction(function () use ($booth, $user) {
            if (! $booth->staff()->whereKey($user->id)->exists()) {
                throw ServiceException::notFound('このブースの担当ではありません');
            }

            $booth->staff()->detach($user->id);

            if ($user->role === UserRole::Staff && ! $user->booths()->exists()) {
                $user->forceFill(['role' => UserRole::User])->save();
            }
        });
    }
}
