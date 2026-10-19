<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBoothRequest;
use App\Http\Resources\AdminBoothResource;
use App\Models\Booth;
use App\Services\AdminBoothService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** 管理者によるブースの管理（ADMIN-03）。認可はルートの role:admin */
class AdminBoothController extends Controller
{
    public function __construct(private readonly AdminBoothService $booths) {}

    public function index(): AnonymousResourceCollection
    {
        return AdminBoothResource::collection($this->booths->list());
    }

    public function show(Booth $booth): AdminBoothResource
    {
        return new AdminBoothResource($booth->loadCount(['staff', 'gameBooths']));
    }

    public function store(StoreBoothRequest $request): JsonResponse
    {
        return (new AdminBoothResource($this->booths->create($request->validated())))->response()->setStatusCode(201);
    }

    public function update(StoreBoothRequest $request, Booth $booth): AdminBoothResource
    {
        return new AdminBoothResource($this->booths->update($booth, $request->validated()));
    }

    public function destroy(Booth $booth): JsonResponse
    {
        $this->booths->delete($booth);

        return response()->json(['message' => 'ブースを削除しました']);
    }
}
