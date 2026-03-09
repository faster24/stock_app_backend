<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\OddSetting\StoreOddSettingRequest;
use App\Http\Requests\OddSetting\UpdateOddSettingRequest;
use App\Models\OddSetting;
use App\Services\OddSetting\OddSettingService;
use Illuminate\Http\JsonResponse;

class OddSettingController extends Controller
{
    public function __construct(private OddSettingService $oddSettingService) {}

    public function index(): JsonResponse
    {
        return $this->respond('Odd settings retrieved successfully.', [
            'odd_settings' => $this->oddSettingService->list(),
        ]);
    }

    public function show(OddSetting $oddSetting): JsonResponse
    {
        return $this->respond('Odd setting retrieved successfully.', [
            'odd_setting' => $this->oddSettingService->show($oddSetting),
        ]);
    }

    public function store(StoreOddSettingRequest $request): JsonResponse
    {
        $oddSetting = $this->oddSettingService->create($request->validated());

        return $this->respond('Odd setting created successfully.', [
            'odd_setting' => $oddSetting,
        ], 201);
    }

    public function update(UpdateOddSettingRequest $request, OddSetting $oddSetting): JsonResponse
    {
        $updatedOddSetting = $this->oddSettingService->update($oddSetting, $request->validated());

        return $this->respond('Odd setting updated successfully.', [
            'odd_setting' => $updatedOddSetting,
        ]);
    }

    public function destroy(OddSetting $oddSetting): JsonResponse
    {
        $this->oddSettingService->delete($oddSetting);

        return $this->respond('Odd setting deleted successfully.', null);
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
