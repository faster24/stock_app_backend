<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\StoreWalletBankInfoRequest;
use App\Http\Requests\Wallet\UpdateWalletBankInfoRequest;
use App\Models\Wallet;
use App\Services\Wallet\WalletBankInfoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletBankInfoController extends Controller
{
    public function __construct(private WalletBankInfoService $walletBankInfoService) {}

    public function show(Request $request): JsonResponse
    {
        $wallet = $this->walletBankInfoService->showForUser($request->user()->id);

        if ($wallet === null) {
            return $this->respond('Bank info not found.', null, 404);
        }

        return $this->respond('Bank info retrieved successfully.', [
            'bank_info' => $this->bankInfoPayload($wallet),
        ]);
    }

    public function store(StoreWalletBankInfoRequest $request): JsonResponse
    {
        $wallet = $this->walletBankInfoService->createForUser($request->user()->id, $request->validated());

        return $this->respond('Bank info created successfully.', [
            'bank_info' => $this->bankInfoPayload($wallet),
        ], 201);
    }

    public function update(UpdateWalletBankInfoRequest $request): JsonResponse
    {
        $wallet = $this->walletBankInfoService->updateForUser($request->user()->id, $request->validated());

        return $this->respond('Bank info updated successfully.', [
            'bank_info' => $this->bankInfoPayload($wallet),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->walletBankInfoService->clearForUser($request->user()->id);

        return $this->respond('Bank info cleared successfully.', null);
    }

    private function bankInfoPayload(Wallet $wallet): array
    {
        return [
            'bank_name' => $wallet->bank_name?->value,
            'account_name' => $wallet->account_name,
            'account_number' => $wallet->account_number,

            // Null until setup is complete — see WalletBankInfoService.
            'bank_info_updated_at' => $wallet->bank_info_updated_at?->toIso8601String(),
            'bank_info_next_allowed_at' => $wallet->bankInfoNextAllowedAt()?->toIso8601String(),
        ];
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
