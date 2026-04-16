<?php

namespace App\Services\AdminBankSetting;

use App\Models\AdminBankSetting;
use App\Services\Service;
use Illuminate\Database\Eloquent\Collection;

class AdminBankSettingService extends Service
{
    public function list(): Collection
    {
        return AdminBankSetting::query()->latest()->get();
    }

    public function listActive(): Collection
    {
        return AdminBankSetting::query()->where('is_active', true)->latest()->get();
    }

    public function show(AdminBankSetting $adminBankSetting): AdminBankSetting
    {
        return $adminBankSetting;
    }

    public function create(array $attributes): AdminBankSetting
    {
        return AdminBankSetting::query()->create($attributes);
    }

    public function update(AdminBankSetting $adminBankSetting, array $attributes): AdminBankSetting
    {
        $adminBankSetting->update($attributes);

        return $adminBankSetting->fresh();
    }

    public function delete(AdminBankSetting $adminBankSetting): void
    {
        $adminBankSetting->delete();
    }
}
