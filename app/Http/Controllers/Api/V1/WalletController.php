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
