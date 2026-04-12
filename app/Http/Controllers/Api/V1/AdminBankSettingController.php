<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminBankSetting\StoreAdminBankSettingRequest;
use App\Http\Requests\AdminBankSetting\UpdateAdminBankSettingRequest;
use App\Models\AdminBankSetting;
use App\Services\AdminBankSetting\AdminBankSettingService;
use Illuminate\Http\JsonResponse;

class AdminBankSettingController extends Controller
{
    public function __construct(private AdminBankSettingService $adminBankSettingService) {}

    public function index(): JsonResponse
    {
        return $this->respond('Admin bank settings retrieved successfully.', [
            'admin_bank_settings' => $this->adminBankSettingService->list(),
        ]);
    }

    public function show(AdminBankSetting $adminBankSetting): JsonResponse
    {
        return $this->respond('Admin bank setting retrieved successfully.', [
            'admin_bank_setting' => $this->adminBankSettingService->show($adminBankSetting),
        ]);
    }

    public function store(StoreAdminBankSettingRequest $request): JsonResponse
    {
        $adminBankSetting = $this->adminBankSettingService->create($request->validated());

        return $this->respond('Admin bank setting created successfully.', [
            'admin_bank_setting' => $adminBankSetting,
        ], 201);
    }

    public function update(UpdateAdminBankSettingRequest $request, AdminBankSetting $adminBankSetting): JsonResponse
    {
        $updated = $this->adminBankSettingService->update($adminBankSetting, $request->validated());

        return $this->respond('Admin bank setting updated successfully.', [
            'admin_bank_setting' => $updated,
        ]);
    }

    public function destroy(AdminBankSetting $adminBankSetting): JsonResponse
    {
        $this->adminBankSettingService->delete($adminBankSetting);

        return $this->respond('Admin bank setting deleted successfully.', null);
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
