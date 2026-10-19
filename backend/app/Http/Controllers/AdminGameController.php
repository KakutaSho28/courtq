<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportGamesRequest;
use App\Http\Requests\StoreGameRequest;
use App\Http\Resources\AdminGameResource;
use App\Models\Game;
use App\Services\AdminGameService;
use App\Services\GameCsvImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** 管理者による試合の管理と CSV 取り込み（ADMIN-01 / 02）。認可はルートの role:admin */
class AdminGameController extends Controller
{
    public function __construct(
        private readonly AdminGameService $games,
        private readonly GameCsvImportService $import,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return AdminGameResource::collection($this->games->list());
    }

    public function show(Game $game): AdminGameResource
    {
        return new AdminGameResource($game->load('venue')->loadCount('gameBooths'));
    }

    public function store(StoreGameRequest $request): JsonResponse
    {
        return (new AdminGameResource($this->games->create($request->validated())))->response()->setStatusCode(201);
    }

    public function update(StoreGameRequest $request, Game $game): AdminGameResource
    {
        return new AdminGameResource($this->games->update($game, $request->validated()));
    }

    public function destroy(Game $game): JsonResponse
    {
        $this->games->delete($game);

        return response()->json(['message' => '試合を削除しました']);
    }

    /** ADMIN-02 CSV の取り込み（dry_run=1 なら保存せずに結果だけ返す） */
    public function import(ImportGamesRequest $request): JsonResponse
    {
        $result = $this->import->import($request->file('file')->get(), $request->boolean('dry_run'));

        return response()->json(['data' => $result]);
    }
}
