<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListBoothsRequest;
use App\Http\Resources\BoothDetailResource;
use App\Http\Resources\BoothListItemResource;
use App\Http\Resources\CongestionHistoryResource;
use App\Models\Booth;
use App\Models\Game;
use App\Services\BoothService;
use App\Support\Freshness;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BoothController extends Controller
{
    public function __construct(private readonly BoothService $booths) {}

    /** BOOTH-01 試合のブース一覧（軽い応答・短時間キャッシュ） */
    public function index(ListBoothsRequest $request, Game $game): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $items = $this->booths->listForGame(
            $game,
            $filters['category'] ?? null,
            $filters['sort'] ?? BoothService::SORT_NAME,
            $request->boolean('in_stock'),
            $request->boolean('favorites'),
            $request->user(),
        );

        return BoothListItemResource::collection($items)->additional(Freshness::meta());
    }

    /** BOOTH-02 ブースの詳細（メニュー・在庫・最終更新） */
    public function show(Request $request, Game $game, Booth $booth): BoothDetailResource
    {
        return (new BoothDetailResource($this->booths->detail($game, $booth, $request->user())))->additional(Freshness::meta());
    }

    /** BOOTH-03 混雑の履歴（グラフ用） */
    public function congestionHistory(Game $game, Booth $booth): AnonymousResourceCollection
    {
        return CongestionHistoryResource::collection($this->booths->congestionHistory($game, $booth));
    }
}
