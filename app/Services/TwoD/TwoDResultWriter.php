<?php

namespace App\Services\TwoD;

use App\Models\TwoDResult;
use App\Support\TwoD\TwoDResultData;

/**
 * Persists a normalized {@see TwoDResultData} into the `two_d_results` table,
 * keyed on history_id for idempotency. Shared by every command that ingests 2D
 * results so the mapping-to-column logic exists once.
 */
class TwoDResultWriter
{
    /**
     * @return bool whether the row was created or changed (as opposed to a no-op update)
     */
    public function write(TwoDResultData $data): bool
    {
        $result = TwoDResult::updateOrCreate(
            ['history_id' => $data->historyId],
            [
                'stock_date' => $data->stockDate,
                'stock_datetime' => $data->stockDateTime,
                'open_time' => $data->openTime,
                'twod' => $data->twod,
                'set_index' => $data->setIndex,
                'value' => $data->value,
                'payload' => $data->raw,
            ]
        );

        return $result->wasRecentlyCreated || $result->wasChanged();
    }
}
