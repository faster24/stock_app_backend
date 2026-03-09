<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'message' => 'Admin dashboard data retrieved successfully.',
            'data' => [
                'dashboard' => [
                    'scope' => 'admin',
                ],
            ],
            'errors' => null,
        ]);
    }
}
