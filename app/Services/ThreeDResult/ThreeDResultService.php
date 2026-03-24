<?php

namespace App\Services\ThreeDResult;

use App\Models\ThreeDResult;
use App\Services\Service;
use Illuminate\Database\Eloquent\Collection;

class ThreeDResultService extends Service
{
    public function list(int $page = 1, int $pageSize = 20, ?string $stockDate = null): Collection
    {
        $resolvedPage = max(1, $page);
        $resolvedPageSize = min(100, max(1, $pageSize));

        return ThreeDResult::query()
            ->when($stockDate !== null && $stockDate !== '', function ($query) use ($stockDate): void {
                $query->whereDate('stock_date', $stockDate);
            })
            ->orderByDesc('stock_date')
            ->orderByDesc('id')
            ->forPage($resolvedPage, $resolvedPageSize)
            ->get();
    }

    public function latest(): ?ThreeDResult
    {
        return ThreeDResult::query()
            ->orderByDesc('stock_date')
            ->orderByDesc('id')
            ->first();
    }

    public function create(array $attributes): ThreeDResult
    {
        return ThreeDResult::query()->create($attributes);
    }

    public function upsertByStockDate(array $attributes): ThreeDResult
    {
        return ThreeDResult::query()->updateOrCreate(
            ['stock_date' => (string) ($attributes['stock_date'] ?? '')],
            ['threed' => (string) ($attributes['threed'] ?? '')]
        );
    }

    public function update(ThreeDResult $threeDResult, array $attributes): ThreeDResult
    {
        $threeDResult->fill($attributes)->save();

        return $threeDResult;
    }

    public function delete(ThreeDResult $threeDResult): void
    {
        $threeDResult->delete();
    }
}
