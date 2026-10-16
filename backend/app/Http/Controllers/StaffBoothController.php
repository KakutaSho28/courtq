<?php

namespace App\Http\Controllers;

use App\Http\Resources\StaffBoothResource;
use App\Services\BoothStaffService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StaffBoothController extends Controller
{
    public function __construct(private readonly BoothStaffService $staff) {}

    /** STAFF-01 管理できるブース（staff は担当ブース、admin は全ブース） */
    public function index(Request $request): AnonymousResourceCollection
    {
        return StaffBoothResource::collection($this->staff->manageableBooths($request->user()));
    }
}
