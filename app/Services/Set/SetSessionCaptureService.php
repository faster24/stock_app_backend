<?php

namespace App\Services\Set;

use App\Contracts\SetScraper;
use App\Enums\SetSession;
use App\Models\SetSessionResult;
use App\Support\Set\SetScrapeResult;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates one SET session capture: trading-day guard -> scrape -> derive 2D
 * -> idempotent upsert into set_session_results (unique result_date+session).
 *
 * Never settles bets. The scrape (browser) is the only slow step; settlement
 * later reads the stored rows via SetIndexProvider.
 */
class SetSessionCaptureService
{
    public function __construct(
        private readonly SetScraper $scraper,
        private readonly TwoDCalculator $calculator,
        private readonly TradingCalendar $calendar,
    ) {}

    /**
     * @return array{status: string, session: string, result_date: string, two_d?: string, stabilized?: bool, reason?: string}
     */
    public function capture(SetSession $session, CarbonInterface $date, bool $force = false): array
    {
        $resultDate = $date->toDateString();

        $base = ['session' => $session->value, 'result_date' => $resultDate];

        if (! $this->calendar->isTradingDay($date)) {
            return $base + ['status' => 'skipped', 'reason' => 'non_trading_day'];
        }

        $existing = SetSessionResult::query()
            ->whereDate('result_date', $resultDate)
            ->where('session', $session->value)
            ->first();

        if ($existing !== null && $existing->stabilized && ! $force) {
            return $base + ['status' => 'skipped', 'reason' => 'already_stabilized'];
        }

        // Throws SetScraperException on transport/process failure — the caller handles it.
        $reading = $this->scraper->capture($session);

        if ($this->shouldAbortOnMarketStatus($reading->marketStatus)) {
            Log::info('set:capture aborted on market status', $base + ['market_status' => $reading->marketStatus]);

            return $base + ['status' => 'skipped', 'reason' => 'market_status'];
        }

        $indexValue = $reading->indexValue($session->indexField());

        if ($indexValue === null || $reading->value === null) {
            return $base + ['status' => 'skipped', 'reason' => 'no_data'];
        }

        // Preserve an already-stabilized reading rather than clobbering it with a
        // later unstable one (unless forced).
        if ($existing !== null && $existing->stabilized && ! $reading->stabilized && ! $force) {
            return $base + ['status' => 'skipped', 'reason' => 'already_stabilized'];
        }

        $digitOne = $this->calculator->indexDigit($indexValue);
        $digitTwo = $this->calculator->valueDigit($reading->value);
        $twoD = $digitOne.$digitTwo;

        SetSessionResult::updateOrCreate(
            ['result_date' => $resultDate, 'session' => $session->value],
            $this->attributes($reading, $indexValue, $twoD, $digitOne, $digitTwo),
        );

        return $base + ['status' => 'stored', 'two_d' => $twoD, 'stabilized' => $reading->stabilized];
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(SetScrapeResult $reading, string $indexValue, string $twoD, string $digitOne, string $digitTwo): array
    {
        return [
            'two_d' => $twoD,
            'digit_one' => $digitOne,
            'digit_two' => $digitTwo,
            'set_index_value' => $indexValue,
            'set_total_value' => $reading->value,
            'market_status' => $reading->marketStatus,
            'market_datetime' => $this->parseMarketDateTime($reading->marketDateTime),
            'stabilized' => $reading->stabilized,
            'attempts' => $reading->attempts,
            'captured_at' => Carbon::now(),
            'raw_payload' => $reading->raw,
        ];
    }

    private function shouldAbortOnMarketStatus(?string $marketStatus): bool
    {
        if ($marketStatus === null) {
            return false;
        }

        return in_array($marketStatus, (array) config('set.abort_market_statuses', []), true);
    }

    private function parseMarketDateTime(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}
