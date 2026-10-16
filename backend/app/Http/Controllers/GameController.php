<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListGamesRequest;
use App\Http\Resources\GameResource;
use App\Models\Game;
use App\Services\GameService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class GameController extends Controller
{
    public function __construct(private readonly GameService $games) {}

    /** GAME-01 試合の一覧（今日の試合が先頭） */
    public function index(ListGamesRequest $request): AnonymousResourceCollection
    {
        $date = $request->validated('date');
        $from = $request->validated('from');

        return GameResource::collection($this->games->list(
            $date ? Carbon::createFromFormat('Y-m-d', $date)->startOfDay() : null,
            $from ? Carbon::createFromFormat('Y-m-d', $from)->startOfDay() : null,
        ));
    }

    /** GAME-02 試合の詳細 */
    public function show(Game $game): GameResource
    {
        return new GameResource($game->load('venue'));
    }
}
