<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WithdrawalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Withdrawal\CompleteWithdrawalRequest;
use App\Http\Requests\Withdrawal\RejectWithdrawalRequest;
use App\Models\Withdrawal;
use App\Services\Withdrawal\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminWithdrawalController extends Controller
{
    public function __construct(private WithdrawalService $withdrawalService) {}

    public function index(Request $request): JsonResponse
    {
        $page     = $request->integer('page', 1);
        $pageSize = $request->integer('page_size', 15);
        $status   = $request->filled('status') ? WithdrawalStatus::tryFrom($request->input('status')) : null;

        $withdrawals = $this->withdrawalService->listForAdmin($page, $pageSize, $status);

        return $this->respond('Withdrawals retrieved successfully.', [
            'withdrawals' => $withdrawals->items(),
            'pagination'  => [
                'current_page' => $withdrawals->currentPage(),
                'last_page'    => $withdrawals->lastPage(),
                'per_page'     => $withdrawals->perPage(),
                'total'        => $withdrawals->total(),
            ],
        ]);
    }

    public function show(Withdrawal $withdrawal): JsonResponse
    {
        return $this->respond('Withdrawal retrieved successfully.', [
            'withdrawal' => $this->withdrawalPayload($withdrawal),
        ]);
    }

    public function complete(CompleteWithdrawalRequest $request, Withdrawal $withdrawal): JsonResponse
    {
        $updated = $this->withdrawalService->complete(
            $withdrawal->id,
            $request->user()->id,
            $request->file('payout_proof'),
            $request->validated('admin_note'),
        );

        return $this->respond('Withdrawal completed successfully.', [
            'withdrawal' => $this->withdrawalPayload($updated),
        ]);
    }

    public function reject(RejectWithdrawalRequest $request, Withdrawal $withdrawal): JsonResponse
    {
        $updated = $this->withdrawalService->reject(
            $withdrawal->id,
            $request->user()->id,
            $request->validated('rejection_reason'),
        );

        return $this->respond('Withdrawal rejected successfully.', [
            'withdrawal' => $this->withdrawalPayload($updated),
        ]);
    }

    private function withdrawalPayload(Withdrawal $withdrawal): array
    {
        return [
            'id'                  => $withdrawal->id,
            'user_id'             => $withdrawal->user_id,
            'currency'            => $withdrawal->currency?->value,
            'amount'              => $withdrawal->amount,
            'status'              => $withdrawal->status?->value,
            'bank_snapshot'       => $withdrawal->bank_snapshot,
            'admin_note'          => $withdrawal->admin_note,
            'rejection_reason'    => $withdrawal->rejection_reason,
            'reviewed_by_user_id' => $withdrawal->reviewed_by_user_id,
            'reviewed_at'         => $withdrawal->reviewed_at?->toIso8601String(),
            'payout_proof'        => $withdrawal->payout_proof,
            'created_at'          => $withdrawal->created_at?->toIso8601String(),
            'updated_at'          => $withdrawal->updated_at?->toIso8601String(),
        ];
    }

    private function respond(string $message, ?array $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data'    => $data,
            'errors'  => null,
        ], $status);
    }
}
