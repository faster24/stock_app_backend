<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TwoDResult\TwoDResultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwoDResultController extends Controller
{
    public function __construct(private readonly TwoDResultService $twoDResultService) {}

    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = min(100, max(1, (int) $request->query('page_size', 20)));

        return $this->respond('2D results retrieved successfully.', [
            'two_d_results' => $this->twoDResultService->list(
                $page,
                $pageSize,
                $request->query('stock_date'),
                $request->query('open_time'),
                $request->query('history_id')
            ),
        ]);
    }

    public function latest(): JsonResponse
    {
        return $this->respond('Latest 2D result retrieved successfully.', [
            'two_d_result' => $this->twoDResultService->latest(),
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
