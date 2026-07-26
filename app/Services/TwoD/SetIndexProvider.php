<?php

namespace App\Services\TwoD;

use App\Contracts\TwoDLiveProvider;
use App\Enums\SetSession;
use App\Models\SetSessionResult;
use App\Support\TwoD\TwoDLiveSnapshot;
use App\Support\TwoD\TwoDResultData;
use Illuminate\Support\Carbon;

/**
 * Settlement adapter for the SET pipeline. Reads the close-session rows that
 * `set:capture` already stored and maps them into the normalized
 * {@see TwoDLiveSnapshot} that BetSettlementService consumes.
 *
 * This is what makes the SET pipeline a drop-in settlement source: set
 * `TWOD_DRIVER=set` and FetchAndSettleTwoDCommand + BetSettlementService run
 * unchanged, reading from the DB instead of hitting a browser.
 */
class SetIndexProvider implements TwoDLiveProvider
{
    public function fetch(): TwoDLiveSnapshot
    {
        $timezone = (string) config('set.timezone', 'Asia/Bangkok');
        $date = Carbon::now($timezone)->toDateString();

        $rows = SetSessionResult::query()
            ->whereDate('result_date', $date)
            ->whereIn('session', [SetSession::MORNING_CLOSE->value, SetSession::EVENING_CLOSE->value])
            ->whereNotNull('two_d')
            ->get();

        $results = [];

        foreach ($rows as $row) {
            $session = $row->session; // cast to SetSession
            $openTime = $session->settlementOpenTime();

            if ($openTime === null) {
                continue;
            }

            $results[] = new TwoDResultData(
                historyId: "set-{$date}-{$session->value}",
                stockDate: $date,
                stockDateTime: $row->market_datetime?->toDateTimeString(),
                openTime: $openTime,
                twod: $row->two_d,
                setIndex: $row->set_index_value,
                value: $row->set_total_value,
                raw: (array) ($row->raw_payload ?? []),
            );
        }

        return new TwoDLiveSnapshot(
            upstreamStatus: 200,
            results: $results,
            live: null,
            raw: ['source' => 'set_session_results', 'result_date' => $date],
        );
    }
}
