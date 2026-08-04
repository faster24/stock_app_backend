<?php

namespace App\Services\PopupAd;

use App\Models\PopupAd;
use App\Services\Service;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;

class PopupAdService extends Service
{
    public function list(): Collection
    {
        return PopupAd::query()->with('media')->latest()->get();
    }

    /**
     * What the client sees: active ads only, newest first. The client shows them
     * in this order, so a newly created ad leads the queue.
     */
    public function listActive(): Collection
    {
        return PopupAd::query()->with('media')->where('is_active', true)->latest()->get();
    }

    public function show(PopupAd $popupAd): PopupAd
    {
        return $popupAd;
    }

    public function create(array $attributes, UploadedFile $image): PopupAd
    {
        $popupAd = PopupAd::query()->create($attributes);

        $popupAd->addMedia($image)->toMediaCollection('ad_image');

        return $popupAd->fresh();
    }

    /**
     * The collection is registered singleFile(), so attaching a new image replaces
     * the old one rather than accumulating.
     */
    public function update(PopupAd $popupAd, array $attributes, ?UploadedFile $image = null): PopupAd
    {
        $popupAd->update($attributes);

        if ($image !== null) {
            $popupAd->addMedia($image)->toMediaCollection('ad_image');
        }

        return $popupAd->fresh();
    }

    public function delete(PopupAd $popupAd): void
    {
        $popupAd->delete();
    }
}
