<?php

namespace App\Http\Controllers;

use App\Services\HealthService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __construct(private readonly HealthService $health) {}

    /** GET /api/health */
    public function __invoke(): JsonResponse
    {
        $result = $this->health->check();

        return response()->json(['data' => $result], $result['status'] === 'ok' ? 200 : 503);
    }
}
