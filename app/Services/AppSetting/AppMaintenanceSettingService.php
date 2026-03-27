<?php

namespace App\Services\AppSetting;

use App\Models\AppMaintenanceSetting;
use App\Services\Service;

class AppMaintenanceSettingService extends Service
{
    public function get(): array
    {
        $setting = AppMaintenanceSetting::query()->first();

        return [
            'is_enabled' => (bool) ($setting?->is_enabled ?? false),
            'message' => $setting?->message,
        ];
    }

    public function update(array $attributes): array
    {
        $isEnabled = (bool) ($attributes['is_enabled'] ?? false);
        $message = $isEnabled ? ($attributes['message'] ?? null) : null;

        $setting = AppMaintenanceSetting::query()->first();

        if ($setting === null) {
            $setting = AppMaintenanceSetting::query()->create([
                'is_enabled' => $isEnabled,
                'message' => $message,
            ]);
        } else {
            $setting->update([
                'is_enabled' => $isEnabled,
                'message' => $message,
            ]);
        }

        return [
            'is_enabled' => (bool) $setting->is_enabled,
            'message' => $setting->message,
        ];
    }
}
