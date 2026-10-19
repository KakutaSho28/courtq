<?php

namespace App\Http\Controllers;

use App\Http\Requests\SyncGameBoothsRequest;
use App\Http\Resources\GameBoothAssignmentResource;
use App\Models\Game;
use App\Services\GameBoothAssignmentService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** 管理者による、試合へのブースの割り当てと営業中 / 閉店中の切り替え（ADMIN-04）。認可はルートの role:admin */
class AdminGameBoothController extends Controller
{
    public function __construct(private readonly GameBoothAssignmentService $assignments) {}

    public function index(Game $game): AnonymousResourceCollection
    {
        return GameBoothAssignmentResource::collection($this->assignments->list($game));
    }

    public function update(SyncGameBoothsRequest $request, Game $game): AnonymousResourceCollection
    {
        return GameBoothAssignmentResource::collection($this->assignments->sync($game, $request->validated('booths')));
    }
}
