<?php

namespace App\Services\TwoDResult;

use App\Models\TwoDResult;
use App\Services\Service;
use Illuminate\Database\Eloquent\Collection;

class TwoDResultService extends Service
{
    public function list(
        int $page = 1,
        int $pageSize = 20,
        ?string $stockDate = null,
        ?string $openTime = null,
        ?string $historyId = null
    ): Collection {
        $resolvedPage = max(1, $page);
        $resolvedPageSize = min(100, max(1, $pageSize));

        return TwoDResult::query()
            ->when($stockDate !== null && $stockDate !== '', function ($query) use ($stockDate): void {
                $query->whereDate('stock_date', $stockDate);
            })
            ->when($openTime !== null && $openTime !== '', function ($query) use ($openTime): void {
                $query->whereTime('open_time', $openTime);
            })
            ->when($historyId !== null && $historyId !== '', function ($query) use ($historyId): void {
                $query->where('history_id', $historyId);
            })
            ->orderByDesc('stock_datetime')
            ->orderByDesc('id')
            ->forPage($resolvedPage, $resolvedPageSize)
            ->get();
    }

    public function latest(): ?TwoDResult
    {
        return TwoDResult::query()
            ->orderByDesc('stock_datetime')
            ->orderByDesc('id')
            ->first();
    }

    public function lastFiveDays(): Collection
    {
        $latestFiveStockDates = TwoDResult::query()
            ->select('stock_date')
            ->distinct()
            ->orderByDesc('stock_date')
            ->limit(5);

        return TwoDResult::query()
            ->whereIn('stock_date', $latestFiveStockDates)
            ->orderByDesc('stock_datetime')
            ->orderByDesc('id')
            ->get();
    }
}
