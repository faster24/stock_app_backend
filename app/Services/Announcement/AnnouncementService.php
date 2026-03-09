<?php

namespace App\Services\Announcement;

use App\Models\Announcement;
use App\Services\Service;
use Illuminate\Database\Eloquent\Collection;

class AnnouncementService extends Service
{
    public function list(): Collection
    {
        return Announcement::query()->latest()->get();
    }

    public function show(Announcement $announcement): Announcement
    {
        return $announcement;
    }

    public function create(array $attributes): Announcement
    {
        return Announcement::query()->create($attributes);
    }

    public function update(Announcement $announcement, array $attributes): Announcement
    {
        $announcement->update($attributes);

        return $announcement->fresh();
    }

    public function delete(Announcement $announcement): void
    {
        $announcement->delete();
    }
}
