<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppSetting\UpdateMaintenanceSettingRequest;
use App\Services\AppSetting\AppMaintenanceSettingService;
use Illuminate\Http\JsonResponse;

class AppSettingController extends Controller
{
    public function __construct(private readonly AppMaintenanceSettingService $appMaintenanceSettingService) {}

    public function maintenance(): JsonResponse
    {
        return $this->respond('Maintenance setting retrieved successfully.', [
            'maintenance' => $this->appMaintenanceSettingService->get(),
        ]);
    }

    public function updateMaintenance(UpdateMaintenanceSettingRequest $request): JsonResponse
    {
        return $this->respond('Maintenance setting updated successfully.', [
            'maintenance' => $this->appMaintenanceSettingService->update($request->validated()),
        ]);
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
