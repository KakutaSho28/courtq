<?php

namespace App\Http\Controllers;

use App\Http\Resources\BoothStaffMemberResource;
use App\Http\Resources\InvitationResource;
use App\Models\Booth;
use App\Models\User;
use App\Services\BoothStaffService;
use App\Services\StaffInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** 管理者によるスタッフの招待と解除（ADMIN-05 / 06）。認可はルートの role:admin */
class AdminBoothStaffController extends Controller
{
    public function __construct(
        private readonly StaffInvitationService $invitations,
        private readonly BoothStaffService $staff,
    ) {}

    /** ADMIN-05 招待コードを発行する */
    public function invite(Request $request, Booth $booth): JsonResponse
    {
        return (new InvitationResource($this->invitations->issue($booth, $request->user())))->response()->setStatusCode(201);
    }

    /** ADMIN-06 ブースの担当スタッフ */
    public function index(Booth $booth): AnonymousResourceCollection
    {
        return BoothStaffMemberResource::collection($this->staff->staffOf($booth));
    }

    /** ADMIN-06 担当から外す */
    public function destroy(Booth $booth, User $user): JsonResponse
    {
        $this->staff->remove($booth, $user);

        return response()->json(['message' => '担当を解除しました']);
    }
}
