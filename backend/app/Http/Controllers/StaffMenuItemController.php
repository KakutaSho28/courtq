<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMenuItemRequest;
use App\Http\Requests\UpdateMenuItemRequest;
use App\Http\Resources\MenuItemResource;
use App\Models\Booth;
use App\Models\MenuItem;
use App\Services\MenuItemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * STAFF-04 メニューの管理。認可はルートで済ませている: role:staff,admin → can:manage,booth（BoothPolicy）。
 * メニューの ID は scopeBindings で「URL のブースのメニュー」に限って解決する（他のブースのメニューは 404）
 */
class StaffMenuItemController extends Controller
{
    public function __construct(private readonly MenuItemService $menu) {}

    /** 一覧（販売終了のメニューも含む） */
    public function index(Booth $booth): AnonymousResourceCollection
    {
        return MenuItemResource::collection($this->menu->list($booth));
    }

    public function store(StoreMenuItemRequest $request, Booth $booth): JsonResponse
    {
        return (new MenuItemResource($this->menu->create($booth, $request->validated())))->response()->setStatusCode(201);
    }

    public function update(UpdateMenuItemRequest $request, Booth $booth, MenuItem $menuItem): MenuItemResource
    {
        return new MenuItemResource($this->menu->update($menuItem, $request->validated()));
    }

    public function destroy(Booth $booth, MenuItem $menuItem): JsonResponse
    {
        $this->menu->delete($menuItem);

        return response()->json(['message' => 'メニューを削除しました']);
    }
}
