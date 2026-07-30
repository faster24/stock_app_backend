<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TwoDResult\TwoDSideNumberService;
use Illuminate\Http\JsonResponse;

/**
 * Read-only access to the display-only modern/internet numbers.
 *
 * Kept separate from {@see TwoDResultController} on purpose: that controller is
 * the settlement-facing read, and these numbers must never be mistaken for a
 * draw result.
 */
class TwoDSideNumberController extends Controller
{
    public function __construct(private readonly TwoDSideNumberService $twoDSideNumberService) {}

    public function lastFiveDays(): JsonResponse
    {
        return $this->respond('Last 5 days 2D side numbers retrieved successfully.', [
            'two_d_side_numbers' => $this->twoDSideNumberService->lastFiveDays(),
        ]);
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
