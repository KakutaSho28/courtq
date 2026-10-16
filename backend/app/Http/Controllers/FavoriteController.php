<?php

namespace App\Http\Controllers;

use App\Http\Resources\FavoriteBoothResource;
use App\Models\Booth;
use App\Services\FavoriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FavoriteController extends Controller
{
    public function __construct(private readonly FavoriteService $favorites) {}

    /** FAV-01 お気に入りのブース */
    public function index(Request $request): AnonymousResourceCollection
    {
        return FavoriteBoothResource::collection($this->favorites->list($request->user()));
    }

    /** FAV-02 お気に入りに追加（追加済みでも成功する） */
    public function store(Request $request, Booth $booth): JsonResponse
    {
        $this->favorites->add($request->user(), $booth);

        return response()->json(['data' => ['booth_id' => $booth->id, 'is_favorite' => true]]);
    }

    /** FAV-03 お気に入りから外す（外れていても成功する） */
    public function destroy(Request $request, Booth $booth): JsonResponse
    {
        $this->favorites->remove($request->user(), $booth);

        return response()->json(['data' => ['booth_id' => $booth->id, 'is_favorite' => false]]);
    }
}
