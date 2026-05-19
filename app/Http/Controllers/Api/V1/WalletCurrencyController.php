<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Currency;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\SetWalletCurrencyRequest;
use App\Models\Wallet;
use App\Services\Wallet\WalletCurrencyService;
use Illuminate\Http\JsonResponse;

class WalletCurrencyController extends Controller
{
    public function __construct(private WalletCurrencyService $walletCurrencyService) {}

    public function set(SetWalletCurrencyRequest $request): JsonResponse
    {
        $currency = Currency::from($request->validated('currency'));
        $wallet = $this->walletCurrencyService->setForUser($request->user()->id, $currency);

        return $this->respond('Wallet currency set successfully.', [
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
