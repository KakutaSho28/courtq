<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcceptInvitationRequest;
use App\Http\Resources\InvitationAcceptedResource;
use App\Services\StaffInvitationService;

class StaffInvitationController extends Controller
{
    public function __construct(private readonly StaffInvitationService $invitations) {}

    /** STAFF-05 招待コードを使ってブースの担当になる（ログインしていれば誰でも） */
    public function accept(AcceptInvitationRequest $request): InvitationAcceptedResource
    {
        $booth = $this->invitations->accept($request->user(), $request->validated('code'));

        return new InvitationAcceptedResource(['user' => $request->user()->refresh(), 'booth' => $booth]);
    }
}
