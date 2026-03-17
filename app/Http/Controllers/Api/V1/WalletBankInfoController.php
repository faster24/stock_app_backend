<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\StoreWalletBankInfoRequest;
use App\Http\Requests\Wallet\UpdateWalletBankInfoRequest;
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

    private function bankInfoPayload(mixed $wallet): array
    {
        return [
            'bank_name' => data_get($wallet, 'bank_name'),
            'account_name' => data_get($wallet, 'account_name'),
            'account_number' => data_get($wallet, 'account_number'),
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
