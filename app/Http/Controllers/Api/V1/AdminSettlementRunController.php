<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settlement\RevertSettlementRunRequest;
use App\Models\SettlementReversal;
use App\Services\Bet\SettlementReversalService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminSettlementRunController extends Controller
{
    public function __construct(private readonly SettlementReversalService $settlementReversalService) {}

    public function index(): JsonResponse
    {
        $runs = DB::table('bet_settlement_runs')
            ->leftJoin('two_d_results', 'two_d_results.id', '=', 'bet_settlement_runs.two_d_result_id')
            ->leftJoin('three_d_results', 'three_d_results.id', '=', 'bet_settlement_runs.three_d_result_id')
            ->select([
                'bet_settlement_runs.history_id',
                'bet_settlement_runs.bet_type',
                'bet_settlement_runs.settled_at',
                'bet_settlement_runs.summary',
                'bet_settlement_runs.created_at',
                DB::raw('COALESCE(two_d_results.stock_date, three_d_results.stock_date) as stock_date'),
                'two_d_results.open_time',
                DB::raw('COALESCE(two_d_results.twod, three_d_results.threed) as winning_number'),
            ])
            ->orderByDesc('bet_settlement_runs.created_at')
            ->limit(200)
            ->get()
            ->map(fn ($row): array => [
                'history_id' => (string) $row->history_id,
                'bet_type' => $row->bet_type,
                'stock_date' => $row->stock_date,
                'open_time' => $row->open_time,
                'winning_number' => $row->winning_number,
                'status' => $row->settled_at !== null ? 'completed' : 'in_progress',
                'settled_at' => $row->settled_at,
                'created_at' => $row->created_at,
                'summary' => $this->decodeJson($row->summary),
                'reversal' => null,
            ]);

        $reversals = SettlementReversal::query()
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(fn (SettlementReversal $reversal): array => [
                'history_id' => $reversal->history_id,
                'bet_type' => $reversal->bet_type,
                'stock_date' => null,
                'open_time' => null,
                'winning_number' => $reversal->reverted_winning_number !== null
                    ? (string) $reversal->reverted_winning_number
                    : null,
                'status' => $reversal->status === SettlementReversal::STATUS_COMPLETED
                    ? 'reverted'
                    : 'reverting',
                'settled_at' => $reversal->run_settled_at?->toDateTimeString(),
                'created_at' => $reversal->created_at?->toDateTimeString(),
                'summary' => $reversal->original_summary,
                'reversal' => [
                    'id' => $reversal->id,
                    'reason' => $reversal->reason,
                    'reverted_by_user_id' => $reversal->reverted_by_user_id,
                    'total_debited' => $reversal->total_debited,
                    'total_shortfall' => $reversal->total_shortfall,
                    'summary' => $reversal->summary,
                    'completed_at' => $reversal->completed_at?->toDateTimeString(),
                ],
            ]);

        $merged = $runs->concat($reversals)
            ->sortByDesc('created_at')
            ->values()
            ->all();

        return $this->respond('Settlement runs retrieved successfully.', [
            'settlement_runs' => $merged,
        ]);
    }

    public function revertPreview(string $historyId): JsonResponse
    {
        try {
            $preview = $this->settlementReversalService->previewRevert($historyId);
        } catch (DomainException $exception) {
            return $this->respond($exception->getMessage(), null, 404, [
                'history_id' => [$exception->getMessage()],
            ]);
        }

        return $this->respond('Revert preview generated successfully.', [
            'preview' => $preview,
        ]);
    }

    public function revert(RevertSettlementRunRequest $request, string $historyId): JsonResponse
    {
        try {
            $reversal = $this->settlementReversalService->revert(
                $historyId,
                (string) $request->user()->id,
                (string) $request->validated()['reason']
            );
        } catch (DomainException $exception) {
            return $this->respond($exception->getMessage(), null, 404, [
                'history_id' => [$exception->getMessage()],
            ]);
        }

        return $this->respond('Settlement run reverted successfully.', [
            'reversal' => $reversal->load('items'),
        ]);
    }

    private function decodeJson(mixed $value): ?array
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
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
