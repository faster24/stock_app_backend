<?php

namespace App\Services\OddSetting;

use App\Models\OddSetting;
use App\Services\Service;
use Illuminate\Database\Eloquent\Collection;

class OddSettingService extends Service
{
    public function list(): Collection
    {
        return OddSetting::query()->latest()->get();
    }

    public function show(OddSetting $oddSetting): OddSetting
    {
        return $oddSetting;
    }

    public function create(array $attributes): OddSetting
    {
        return OddSetting::query()->create($attributes);
    }

    public function update(OddSetting $oddSetting, array $attributes): OddSetting
    {
        $oddSetting->update($attributes);

        return $oddSetting->fresh();
    }

    public function delete(OddSetting $oddSetting): void
    {
        $oddSetting->delete();
    }
}
