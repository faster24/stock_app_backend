<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminWalletController extends Controller
{
    public function show(User $user): JsonResponse
    {
        $wallet = Wallet::query()->where('user_id', $user->id)->first();

        if ($wallet === null) {
            return $this->respond('Wallet not found.', null, 404);
        }

        return $this->respond('Wallet retrieved successfully.', [
            'wallet' => $this->walletPayload($wallet),
        ]);
    }

    public function transactions(Request $request, User $user): JsonResponse
    {
        $query = WalletTransaction::query()
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc');

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }

        $transactions = $query->paginate($request->integer('page_size', 15));

        return $this->respond('Transactions retrieved successfully.', [
            'transactions' => $transactions->items(),
            'pagination'   => [
                'current_page' => $transactions->currentPage(),
                'last_page'    => $transactions->lastPage(),
                'per_page'     => $transactions->perPage(),
                'total'        => $transactions->total(),
            ],
        ]);
    }

    private function walletPayload(Wallet $wallet): array
    {
        return [
            'id'                 => $wallet->id,
            'balance'            => $wallet->balance,
            'currency'           => $wallet->currency?->value,
            'currency_locked_at' => $wallet->currency_locked_at?->toIso8601String(),
            'bank_name'          => $wallet->bank_name?->value,
            'account_name'       => $wallet->account_name,
            'account_number'     => $wallet->account_number,
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
