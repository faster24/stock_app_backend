<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\AdminBalanceAdjustmentRequest;
use App\Models\User;
use App\Services\Wallet\WalletMutator;
use DomainException;
use Illuminate\Http\JsonResponse;

class AdminBalanceAdjustmentController extends Controller
{
    public function __construct(private WalletMutator $walletMutator) {}

    public function store(AdminBalanceAdjustmentRequest $request, User $user): JsonResponse
    {
        $validated = $request->validated();

        try {
            $txn = $this->walletMutator->mutate(
                userId: $user->id,
                type: WalletTransactionType::ADJUSTMENT,
                direction: WalletTransactionDirection::from($validated['direction']),
                amount: (int) $validated['amount'],
                reference: null,
                createdByUserId: (string) $request->user()->id,
                note: $validated['reason'],
            );
        } catch (DomainException $e) {
            return $this->respond($e->getMessage(), null, 409, [
                'balance' => [$e->getMessage()],
            ]);
        }

        return $this->respond('Balance adjusted successfully.', [
            'transaction' => [
                'id'            => $txn->id,
                'user_id'       => $txn->user_id,
                'type'          => $txn->type,
                'direction'     => $txn->direction,
                'amount'        => $txn->amount,
                'balance_after' => $txn->balance_after,
                'currency'      => $txn->currency,
                'note'          => $txn->note,
                'created_at'    => $txn->created_at?->toIso8601String(),
            ],
        ]);
    }

    private function respond(string $message, ?array $data, int $status = 200, ?array $errors = null): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data'    => $data,
            'errors'  => $errors,
        ], $status);
    }
}
