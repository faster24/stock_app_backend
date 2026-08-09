<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SettlementRevertRequiredException;
use App\Http\Controllers\Controller;
use App\Http\Requests\TwoDResult\StoreTwoDResultRequest;
use App\Http\Requests\TwoDResult\UpdateTwoDResultRequest;
use App\Models\TwoDResult;
use App\Services\Bet\SettlementRecoveryService;
use App\Services\TwoD\TwoDLiveTickerService;
use App\Services\TwoDResult\TwoDResultService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwoDResultController extends Controller
{
    public function __construct(
        private readonly TwoDResultService $twoDResultService,
        private readonly SettlementRecoveryService $settlementRecoveryService,
    ) {}

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

    public function lastFiveDays(): JsonResponse
    {
        return $this->respond('Last 5 days 2D results retrieved successfully.', [
            'two_d_results' => $this->twoDResultService->lastFiveDays(),
        ]);
    }

    /**
     * The home-screen ticker. Preview only — never a settlement source.
     *
     * Method-injected rather than constructor-injected so the upstream provider
     * chain is not built for the sibling endpoints, which never touch it.
     */
    public function live(TwoDLiveTickerService $ticker): JsonResponse
    {
        return $this->respond('Live 2D value retrieved successfully.', [
            'live' => $ticker->current(),
        ]);
    }

    public function store(StoreTwoDResultRequest $request): JsonResponse
    {
        try {
            $outcome = $this->settlementRecoveryService->createManualTwoDResult(
                $request->validated(),
                (string) $request->user()->id
            );
        } catch (DomainException $exception) {
            return $this->respond($exception->getMessage(), null, 409, [
                'result' => [$exception->getMessage()],
            ]);
        }

        return $this->respond('2D result saved and settled successfully.', [
            'two_d_result' => $outcome['result']->fresh(),
            'settlement_summary' => $outcome['summary'],
        ], 201);
    }

    public function update(UpdateTwoDResultRequest $request, TwoDResult $twoDResult): JsonResponse
    {
        $validated = $request->validated();

        try {
            $outcome = $this->settlementRecoveryService->correctTwoDResult(
                $twoDResult,
                (string) $validated['twod'],
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

        return $this->respond('2D result corrected and re-settled successfully.', [
            'two_d_result' => $outcome['result']->fresh(),
            'reversal' => $outcome['reversal'],
            'settlement_summary' => $outcome['summary'],
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
