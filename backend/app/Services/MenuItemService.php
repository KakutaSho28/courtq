<?php

namespace App\Services;

use App\Exceptions\ServiceException;
use App\Models\Booth;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;

/** メニューの管理（STAFF-04）。誰が操作してよいかは呼び出し前に Policy で確認済みの前提 */
class MenuItemService
{
    /** クライアントから変更を受け付ける項目（booth_id などはここに入れない） */
    private const EDITABLE = ['name', 'kind', 'price', 'description', 'is_active'];

    public function __construct(private readonly BoothService $booths) {}

    /**
     * ブースのメニュー一覧（STAFF-04）。来場者向けの詳細と違い、販売終了（is_active=false）のものも含める
     * （スタッフが販売を再開できるように）。
     *
     * @return Collection<int, MenuItem>
     */
    public function list(Booth $booth): Collection
    {
        return $booth->menuItems()->orderBy('kind')->orderBy('id')->get();
    }

    /** @param array<string, mixed> $data */
    public function create(Booth $booth, array $data): MenuItem
    {
        $item = $booth->menuItems()->create(Arr::only($data, self::EDITABLE));
        $this->booths->forgetListsForBooth($booth);

        return $item->refresh();
    }

    /** @param array<string, mixed> $data */
    public function update(MenuItem $item, array $data): MenuItem
    {
        $item->update(Arr::only($data, self::EDITABLE));
        $this->booths->forgetListsForBooth($item->booth);

        return $item;
    }

    /**
     * 在庫の記録がまだ無いメニューだけ削除できる。削除すると在庫と在庫の履歴も消えてしまうため、
     * 販売をやめるときは is_active=false にしてもらう。
     */
    public function delete(MenuItem $item): void
    {
        if ($item->stocks()->exists()) {
            throw new ServiceException('在庫の記録があるメニューは削除できません。販売をやめるときは「販売中」をオフにしてください', 422);
        }

        $booth = $item->booth;
        $item->delete();
        $this->booths->forgetListsForBooth($booth);
    }
}
