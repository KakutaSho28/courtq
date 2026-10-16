<?php

namespace App\Http\Controllers;

use App\Enums\CongestionLevel;
use App\Enums\StockStatus;
use App\Http\Requests\UpdateCongestionRequest;
use App\Http\Requests\UpdateStockRequest;
use App\Http\Resources\GameBoothStatusResource;
use App\Http\Resources\UpdatedStockResource;
use App\Models\Booth;
use App\Models\Game;
use App\Models\MenuItem;
use App\Services\BoothStatusService;
use Illuminate\Http\JsonResponse;

/**
 * 混雑・在庫の更新。認可はルートで済ませている: role:staff,admin → can:manage（BoothPolicy / MenuItemPolicy）→ throttle:staff-write
 */
class StaffStatusController extends Controller
{
    public function __construct(private readonly BoothStatusService $status) {}

    /** STAFF-02 混雑の更新 */
    public function updateCongestion(UpdateCongestionRequest $request, Game $game, Booth $booth): GameBoothStatusResource
    {
        return new GameBoothStatusResource($this->status->updateCongestion(
            $game,
            $booth,
            $request->user(),
            CongestionLevel::from((int) $request->validated('level')),
            $request->validated('wait_minutes'),
        ));
    }

    /** STAFF-03 在庫の更新。初めての登録でも 200（PUT は作成・更新のどちらでも同じ応答にする） */
    public function updateStock(UpdateStockRequest $request, Game $game, MenuItem $menuItem): JsonResponse
    {
        $stock = $this->status->updateStock(
            $game,
            $menuItem,
            $request->user(),
            StockStatus::from($request->validated('status')),
            $request->validated('quantity'),
        );

        return (new UpdatedStockResource($stock))->response()->setStatusCode(200);
    }
}
