<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Health\ThaiStockLiveHealthService;
use Illuminate\Http\JsonResponse;

class AdminHealthController extends Controller
{
    public function __construct(private readonly ThaiStockLiveHealthService $thaiStockLiveHealthService) {}

    public function thaiStock2dLive(): JsonResponse
    {
        $health = $this->thaiStockLiveHealthService->checkThaiStock2dLive();
        $healthy = (bool) ($health['healthy'] ?? false);

        return $this->respond(
            $healthy ? 'ThaiStock2D live health check passed.' : 'ThaiStock2D live health check failed.',
            ['health' => $health],
            $healthy ? 200 : 503
        );
    }

    private function respond(string $message, ?array $data, int $status = 200, ?array $errors = null): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
        ], $status);
    }
}
