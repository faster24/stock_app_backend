<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Withdrawal\StoreWithdrawalRequest;
use App\Models\Withdrawal;
use App\Services\Withdrawal\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WithdrawalController extends Controller
{
    public function __construct(private WithdrawalService $withdrawalService) {}

    public function index(Request $request): JsonResponse
    {
        $page     = $request->integer('page', 1);
        $pageSize = $request->integer('page_size', 15);

        $withdrawals = $this->withdrawalService->listForUser($request->user()->id, $page, $pageSize);

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

    public function store(StoreWithdrawalRequest $request): JsonResponse
    {
        $withdrawal = $this->withdrawalService->createForUser(
            $request->user()->id,
            $request->validated(),
        );

        return $this->respond('Withdrawal submitted successfully.', [
            'withdrawal' => $this->withdrawalPayload($withdrawal),
        ], 201);
    }

    public function show(Request $request, string $withdrawal): JsonResponse
    {
        $found = $this->withdrawalService->showForUser($request->user()->id, $withdrawal);

        if ($found === null) {
            return $this->respond('Withdrawal not found.', null, 404);
        }

        return $this->respond('Withdrawal retrieved successfully.', [
            'withdrawal' => $this->withdrawalPayload($found),
        ]);
    }

    public function downloadProof(Request $request, string $withdrawal): BinaryFileResponse|JsonResponse
    {
        $user  = $request->user();
        $found = Withdrawal::query()->with('media')->whereKey($withdrawal)->first();

        if ($found === null) {
            return $this->respond('Withdrawal not found.', null, 404);
        }

        if (! $user->hasRole('admin') && $found->user_id !== (string) $user->id) {
            return $this->respond('Withdrawal not found.', null, 404);
        }

        if ($found->status !== \App\Enums\WithdrawalStatus::COMPLETED) {
            return $this->respond('Payout proof is only available for completed withdrawals.', null, 404);
        }

        $media = $found->getFirstMedia('payout_proof');

        if ($media === null) {
            return $this->respond('Payout proof not found.', null, 404);
        }

        return response()->download(
            $media->getPath(),
            $media->file_name,
            array_filter(['Content-Type' => $media->mime_type])
        );
    }

    public function cancel(Request $request, string $withdrawal): JsonResponse
    {
        $cancelled = $this->withdrawalService->cancelForUser($request->user()->id, $withdrawal);

        return $this->respond('Withdrawal cancelled successfully.', [
            'withdrawal' => $this->withdrawalPayload($cancelled),
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
