<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Admin\PendingRequestCountsService;
use Illuminate\Http\JsonResponse;

class AdminPendingCountsController extends Controller
{
    public function __construct(private PendingRequestCountsService $pendingRequestCountsService) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'message' => 'Pending request counts retrieved successfully.',
            'data' => $this->pendingRequestCountsService->counts(),
            'errors' => null,
        ]);
    }
}
