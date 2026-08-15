<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $wallet = Wallet::query()->where('user_id', $request->user()->id)->first();

        if ($wallet === null) {
            return $this->respond('Wallet not found.', null, 404);
        }

        return $this->respond('Wallet retrieved successfully.', [
            'wallet' => $this->walletPayload($wallet),
        ]);
    }

    private function walletPayload(Wallet $wallet): array
    {
        return [
            'id' => $wallet->id,
            'balance' => $wallet->balance,
            'currency' => $wallet->currency?->value,
            'currency_locked_at' => $wallet->currency_locked_at?->toIso8601String(),
            'bank_name' => $wallet->bank_name?->value,
            'account_name' => $wallet->account_name,
            'account_number' => $wallet->account_number,

            // Null until setup is complete. Clients read the next-allowed stamp
            // to show the unlock date rather than letting the user submit a
            // change that is guaranteed to be rejected.
            'bank_info_updated_at' => $wallet->bank_info_updated_at?->toIso8601String(),
            'bank_info_next_allowed_at' => $wallet->bankInfoNextAllowedAt()?->toIso8601String(),
        ];
    }

    private function respond(string $message, ?array $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => $data,
            'errors' => null,
        ], $status);
    }
}
