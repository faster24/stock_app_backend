<?php

namespace App\Services\TwoD;

use App\Contracts\TwoDLiveProvider;
use App\Enums\TwoDSideSlot;
use App\Exceptions\TwoDProviderException;
use App\Models\TwoDSideNumber;
use App\Services\Service;
use App\Services\Set\TradingCalendar;
use App\Support\TwoD\TwoDPayloadNormalizer;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Captures one slot's `modern`/`internet` pair from HtayApi into
 * two_d_side_numbers (unique result_date + slot).
 *
 * STORE ONLY. This service never writes two_d_results, never touches
 * BetSettlementService or bet_settlement_runs, and nothing it stores can settle
 * a bet. That isolation is the whole point of the separate table.
 *
 * The values are read off TwoDLiveSnapshot::$raw — the untouched upstream
 * payload the provider already carries — so the settlement mapper needs no
 * change and keeps ignoring `modern`/`internet` as it always has.
 */
class TwoDSideNumberCaptureService extends Service
{
    public function __construct(
        private readonly TwoDLiveProvider $provider,
        private readonly TwoDPayloadNormalizer $normalizer,
        private readonly TwoDSideNumberFreshnessGuard $freshnessGuard,
        private readonly TradingCalendar $calendar,
    ) {}

    /**
     * @return array{status: string, slot: string, result_date: string, modern?: ?string, internet?: ?string, reason?: string}
     */
    public function capture(TwoDSideSlot $slot, CarbonInterface $date, bool $force = false): array
    {
        $resultDate = $date->toDateString();

        $base = ['slot' => $slot->value, 'result_date' => $resultDate];

        // Steps 1-3 deliberately run BEFORE any network call: each fetch spends
        // one unit of the shared HtayApi daily budget, which the settlement
        // scheduler also draws on.

        if ((string) config('services.twod.driver') !== 'htayapi') {
            // `modern`/`internet` exist only in HtayApi's payload. Fail loudly
            // rather than quietly writing null rows under another driver.
            return $base + ['status' => 'skipped', 'reason' => 'driver_unsupported'];
        }

        if (! $this->calendar->isTradingDay($date)) {
            return $base + ['status' => 'skipped', 'reason' => 'non_trading_day'];
        }

        if (! $force && $this->alreadyCaptured($slot, $resultDate)) {
            return $base + ['status' => 'skipped', 'reason' => 'already_captured'];
        }

        try {
            $snapshot = $this->provider->fetch();
        } catch (TwoDProviderException $exception) {
            return $base + ['status' => 'skipped', 'reason' => 'upstream_error', 'message' => $exception->getMessage()];
        }

        $block = $snapshot->raw[$slot->value] ?? null;

        if (! is_array($block)) {
            return $base + ['status' => 'skipped', 'reason' => 'payload_shape'];
        }

        $modern = $this->readNumber($block, 'modern');
        $internet = $this->readNumber($block, 'internet');

        if ($modern === null && $internet === null) {
            return $base + ['status' => 'skipped', 'reason' => 'no_data'];
        }

        if (! $this->freshnessGuard->isFresh($slot, $modern, $internet)) {
            return $base + ['status' => 'skipped', 'reason' => 'stale'];
        }

        TwoDSideNumber::updateOrCreate(
            ['result_date' => $resultDate, 'slot' => $slot->value],
            [
                'modern' => $modern,
                'internet' => $internet,
                'captured_at' => Carbon::now(),
                'raw_payload' => $block,
            ],
        );

        return $base + ['status' => 'stored', 'modern' => $modern, 'internet' => $internet];
    }

    /** A row counts as captured only once both halves are present. */
    private function alreadyCaptured(TwoDSideSlot $slot, string $resultDate): bool
    {
        return TwoDSideNumber::query()
            ->whereDate('result_date', $resultDate)
            ->where('slot', $slot->value)
            ->whereNotNull('modern')
            ->whereNotNull('internet')
            ->exists();
    }

    /**
     * `--` is HtayApi's placeholder for "not published", the same convention the
     * settlement mapper applies to the `2d` field.
     *
     * Anything that is not exactly two digits is treated the same way. Upstream
     * served a bare `"0"` for both halves of the morning pair on 2026-08-24 and
     * it was stored verbatim: the freshness guard passed it (it differed from
     * the previous day) and clients rendered it. A malformed value is upstream
     * still warming up, not a result, so returning null lets the retry loop try
     * again instead of writing junk that looks settled.
     */
    private function readNumber(array $block, string $key): ?string
    {
        $value = $this->normalizer->string($block[$key] ?? null);

        return ($value !== null && preg_match('/^\d{2}$/', $value) === 1) ? $value : null;
    }
}
