<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVenueRequest;
use App\Http\Resources\VenueResource;
use App\Services\VenueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** 会場の一覧と登録（試合の登録フォーム・CSV の会場名の照合用）。認可はルートの role:admin */
class AdminVenueController extends Controller
{
    public function __construct(private readonly VenueService $venues) {}

    public function index(): AnonymousResourceCollection
    {
        return VenueResource::collection($this->venues->list());
    }

    public function store(StoreVenueRequest $request): JsonResponse
    {
        return (new VenueResource($this->venues->create($request->validated())))->response()->setStatusCode(201);
    }
}
