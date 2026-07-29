<?php

namespace App\Services\TwoDResult;

use App\Models\TwoDResult;
use App\Services\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class TwoDResultService extends Service
{
    /**
     * Newest slot first.
     *
     * Ordering deliberately avoids `stock_datetime`: it is nullable, and a
     * provider that omits it (htayapi did) sank every one of its rows below
     * every other row regardless of date, since MySQL sorts NULLs last on a
     * DESC sort. `stock_date` + `open_time` are always populated and express
     * the same intent directly.
     */
    private function newestFirst(Builder $query): Builder
    {
        return $query
            ->orderByDesc('stock_date')
            ->orderByDesc('open_time')
            ->orderByDesc('id');
    }

    public function list(
        int $page = 1,
        int $pageSize = 20,
        ?string $stockDate = null,
        ?string $openTime = null,
        ?string $historyId = null
    ): Collection {
        $resolvedPage = max(1, $page);
        $resolvedPageSize = min(100, max(1, $pageSize));

        $query = TwoDResult::query()
            ->when($stockDate !== null && $stockDate !== '', function ($query) use ($stockDate): void {
                $query->whereDate('stock_date', $stockDate);
            })
            ->when($openTime !== null && $openTime !== '', function ($query) use ($openTime): void {
                $query->whereTime('open_time', $openTime);
            })
            ->when($historyId !== null && $historyId !== '', function ($query) use ($historyId): void {
                $query->where('history_id', $historyId);
            });

        return $this->newestFirst($query)
            ->forPage($resolvedPage, $resolvedPageSize)
            ->get();
    }

    public function latest(): ?TwoDResult
    {
        return $this->newestFirst(TwoDResult::query())->first();
    }

    public function lastFiveDays(): Collection
    {
        $latestFiveStockDates = TwoDResult::query()
            ->select('stock_date')
            ->distinct()
            ->orderByDesc('stock_date')
            ->limit(5)
            ->pluck('stock_date');

        return $this->newestFirst(
            TwoDResult::query()->whereIn('stock_date', $latestFiveStockDates)
        )->get();
    }
}
