<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ThreeDResult\StoreThreeDResultRequest;
use App\Http\Requests\ThreeDResult\UpdateThreeDResultRequest;
use App\Models\ThreeDResult;
use App\Services\Bet\BetSettlementService;
use App\Services\ThreeDResult\ThreeDResultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ThreeDResultController extends Controller
{
    public function __construct(
        private readonly ThreeDResultService $threeDResultService,
        private readonly BetSettlementService $betSettlementService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = min(100, max(1, (int) $request->query('page_size', 20)));

        return $this->respond('3D results retrieved successfully.', [
            'three_d_results' => $this->threeDResultService->list(
                $page,
                $pageSize,
                $request->query('stock_date')
            ),
        ]);
    }

    public function latest(): JsonResponse
    {
        return $this->respond('Latest 3D result retrieved successfully.', [
            'three_d_result' => $this->threeDResultService->latest(),
        ]);
    }

    public function store(StoreThreeDResultRequest $request): JsonResponse
    {
        $threeDResult = $this->threeDResultService->upsertByStockDate($request->validated());

        if ($threeDResult->wasRecentlyCreated || $threeDResult->wasChanged()) {
            $this->betSettlementService->settleThreeDResult($threeDResult);
        }

        return $this->respond('3D result saved successfully.', [
            'three_d_result' => $threeDResult->fresh(),
        ], $threeDResult->wasRecentlyCreated ? 201 : 200);
    }

    public function update(UpdateThreeDResultRequest $request, ThreeDResult $threeDResult): JsonResponse
    {
        $updatedResult = $this->threeDResultService->update($threeDResult, $request->validated());

        if ($updatedResult->wasChanged()) {
            $this->betSettlementService->settleThreeDResult($updatedResult);
        }

        return $this->respond('3D result updated successfully.', [
            'three_d_result' => $updatedResult->fresh(),
        ]);
    }

    public function destroy(ThreeDResult $threeDResult): JsonResponse
    {
        $this->threeDResultService->delete($threeDResult);

        return $this->respond('3D result deleted successfully.', null);
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
