<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SettlementRevertRequiredException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ThreeDResult\StoreThreeDResultRequest;
use App\Http\Requests\ThreeDResult\UpdateThreeDResultRequest;
use App\Models\ThreeDResult;
use App\Services\Bet\BetSettlementService;
use App\Services\Bet\SettlementRecoveryService;
use App\Services\ThreeDResult\ThreeDResultService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ThreeDResultController extends Controller
{
    public function __construct(
        private readonly ThreeDResultService $threeDResultService,
        private readonly BetSettlementService $betSettlementService,
        private readonly SettlementRecoveryService $settlementRecoveryService
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
        $validated = $request->validated();

        try {
            $outcome = $this->settlementRecoveryService->correctThreeDResult(
                $threeDResult,
                array_intersect_key($validated, array_flip(['stock_date', 'threed'])),
                (string) $request->user()->id,
                $validated['reason'] ?? null,
                (bool) ($validated['confirm_revert'] ?? false)
            );
        } catch (SettlementRevertRequiredException $exception) {
            return $this->respond($exception->getMessage(), [
                'requires_revert' => true,
                'history_id' => $exception->historyId,
            ], 409, ['result' => [$exception->getMessage()]]);
        } catch (DomainException $exception) {
            return $this->respond($exception->getMessage(), null, 409, [
                'result' => [$exception->getMessage()],
            ]);
        }

        return $this->respond('3D result updated successfully.', [
            'three_d_result' => $outcome['result']->fresh(),
            'reversal' => $outcome['reversal'],
            'settlement_summary' => $outcome['summary'],
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
