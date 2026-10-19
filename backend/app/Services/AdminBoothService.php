<?php

namespace App\Services;

use App\Exceptions\ServiceException;
use App\Models\Booth;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;

/** 管理者によるブースの登録・編集・削除（ADMIN-03）。誰が操作してよいかは、ルートの role:admin で確認済みの前提 */
class AdminBoothService
{
    private const EDITABLE = ['name', 'category', 'location_label', 'description'];

    /**
     * ブースの一覧（担当スタッフの数・出店している試合の数つき）
     *
     * @return Collection<int, Booth>
     */
    public function list(): Collection
    {
        return Booth::query()->withCount(['staff', 'gameBooths'])->orderBy('name')->orderBy('id')->get();
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): Booth
    {
        return Booth::create(Arr::only($data, self::EDITABLE))->loadCount(['staff', 'gameBooths']);
    }

    /** @param array<string, mixed> $data */
    public function update(Booth $booth, array $data): Booth
    {
        $booth->update(Arr::only($data, self::EDITABLE));
        // カテゴリや名前は来場者の一覧に出ているので、出店しているすべての試合の一覧キャッシュを捨てる
        app(BoothService::class)->forgetListsForBooth($booth);

        return $booth->refresh()->loadCount(['staff', 'gameBooths']);
    }

    /**
     * 試合に割り当てていないブースだけ削除できる（割り当て済みだと、試合ごとの混雑・在庫の履歴も消えてしまうため）。
     * メニュー・担当スタッフ・招待コードはブースと一緒に消える。
     */
    public function delete(Booth $booth): void
    {
        if ($booth->gameBooths()->exists()) {
            throw new ServiceException('試合に割り当て済みのブースは削除できません。先に試合への割り当てを外してください', 422);
        }

        $booth->delete();
    }
}
