<?php

namespace App\Console\Commands;

use App\Contracts\TwoDLiveProvider;
use App\Exceptions\TwoDProviderException;
use App\Models\TwoDResult;
use App\Services\Bet\BetSettlementService;
use App\Services\TwoD\TwoDResultWriter;
use App\Support\Sleeper;
use App\Support\TwoD\TwoDLiveData;
use App\Support\TwoD\TwoDLiveSnapshot;
use App\Support\TwoD\TwoDPayloadNormalizer;
use App\Support\TwoD\TwoDResultData;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchAndSettleTwoDCommand extends Command
{
    protected $signature = 'twod:fetch-and-settle
                            {open_time : The open_time slot to settle after fetch, e.g. "12:01"}
                            {--timeout-minutes=120 : Total minutes to keep retrying before giving up}
                            {--retry-interval=60 : Seconds to wait between retry attempts}
                            {--max-attempts=0 : Hard cap on fetch attempts; 0 = unlimited (use timeout only)}
                            {--chunk-size=500 : Bet settlement chunk size}
                            {--no-live-fallback : Do not fall back to live data on timeout; just give up}';

    protected $description = 'Fetch 2D live results with time-based retry, then settle bets for the given open_time slot.';

    public function __construct(
        private readonly BetSettlementService $settlementService,
        private readonly Sleeper $sleeper,
        private readonly TwoDLiveProvider $provider,
        private readonly TwoDResultWriter $writer,
        private readonly TwoDPayloadNormalizer $normalizer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $openTime = trim($this->argument('open_time'));
        $timeoutMinutes = max(1, (int) $this->option('timeout-minutes'));
        $retryInterval = max(10, (int) $this->option('retry-interval'));
        $chunkSize = max(1, (int) $this->option('chunk-size'));

        $maxAttempts = max(0, (int) $this->option('max-attempts'));

        $fetched = $this->fetchWithRetry($openTime, $timeoutMinutes, $retryInterval, $maxAttempts);

        if ($fetched === null) {
            return self::FAILURE;
        }

        if ($fetched['type'] === 'live') {
            if (! $this->persistLiveFallback($fetched['snapshot']->live, $openTime)) {
                return self::FAILURE;
            }
        } else {
            if (! $this->persistResults($fetched['snapshot'])) {
                return self::FAILURE;
            }
        }

        return $this->settle($openTime, $chunkSize);
    }

    /**
     * @return array{type: string, snapshot: TwoDLiveSnapshot}|null
     */
    private function fetchWithRetry(string $openTime, int $timeoutMinutes, int $retryInterval, int $maxAttempts = 0): ?array
    {
        $deadline = now()->addMinutes($timeoutMinutes);
        $attempt = 0;
        $lastSnapshot = null;

        while (true) {
            $attempt++;
            $remainingMinutes = (int) now()->diffInMinutes($deadline, false);
            $this->info("Attempt {$attempt}: fetching from 2D provider... ({$remainingMinutes}m remaining)");

            $snapshot = $this->tryFetch();

            if ($snapshot !== null) {
                $lastSnapshot = $snapshot;

                if ($snapshot->hasResultFor($openTime)) {
                    $this->info("Fetch succeeded on attempt {$attempt}.");

                    return ['type' => 'result', 'snapshot' => $snapshot];
                }

                $this->info("Result for open_time={$openTime} not yet in payload.");
            }

            if (now()->greaterThanOrEqualTo($deadline)) {
                break;
            }

            if ($maxAttempts > 0 && $attempt >= $maxAttempts) {
                break;
            }

            $secondsLeft = (int) now()->diffInSeconds($deadline, false);
            $wait = min($retryInterval, $secondsLeft);

            $this->warn("Retrying in {$wait}s...");
            $this->sleeper->sleep($wait);
        }

        $live = $lastSnapshot?->live;

        if (! $this->option('no-live-fallback')
            && $live !== null
            && $live->twod !== null
            && $live->twod !== '--'
            && $this->liveTimeMatchesSlot($live->time ?? '', $openTime)) {
            $this->warn("Timeout reached after {$timeoutMinutes}m ({$attempt} attempts). Falling back to live data.");
            Log::warning('twod:fetch-and-settle: using live fallback', [
                'open_time' => $openTime,
                'attempts' => $attempt,
                'live' => $live->raw,
            ]);

            return ['type' => 'live', 'snapshot' => $lastSnapshot];
        }

        Log::critical('twod:fetch-and-settle timed out with no usable data.', [
            'open_time' => $openTime,
            'timeout_minutes' => $timeoutMinutes,
            'attempts' => $attempt,
        ]);
        $this->error("Timed out after {$timeoutMinutes} minutes ({$attempt} attempts). No live fallback available. Bets will NOT be settled.");

        return null;
    }

    private function tryFetch(): ?TwoDLiveSnapshot
    {
        try {
            return $this->provider->fetch();
        } catch (TwoDProviderException $e) {
            $this->error("Fetch failed: {$e->getMessage()}");

            return null;
        }
    }

    private function liveTimeMatchesSlot(string $liveTime, string $openTime): bool
    {
        // live.time is a full datetime ("2026-05-05 16:31:58"); open_time is "HH:MM".
        // The market closes a minute or two after the slot opens, so we match by hour.
        try {
            $liveHour = (int) Carbon::parse($liveTime)->format('H');
            $slotHour = (int) Carbon::createFromFormat('H:i', $openTime)->format('H');

            return $liveHour === $slotHour;
        } catch (Throwable) {
            return false;
        }
    }

    private function persistResults(TwoDLiveSnapshot $snapshot): bool
    {
        $fetched = count($snapshot->results);
        $saved = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($snapshot->results as $result) {
            try {
                if ($this->writer->write($result)) {
                    $saved++;
                } else {
                    $skipped++;
                }
            } catch (Throwable) {
                $failed++;
            }
        }

        $this->info("Persist — Fetched: {$fetched}, Saved: {$saved}, Skipped: {$skipped}, Failed: {$failed}");

        if ($failed > 0) {
            $this->warn("Persist — {$failed} row(s) failed. Settlement will proceed if target result exists.");
            Log::warning('twod:fetch-and-settle: partial persist failures', ['failed' => $failed]);
        }

        if ($fetched > 0 && $failed === $fetched) {
            $this->error('All rows failed during persistence. Aborting settlement.');

            return false;
        }

        return true;
    }

    private function persistLiveFallback(?TwoDLiveData $live, string $openTime): bool
    {
        if ($live === null) {
            $this->error('Live fallback requested but no live data is available.');

            return false;
        }

        $historyDate = $live->date ?? now('Asia/Yangon')->toDateString();
        $historyId = '2d-live-'.$historyDate.'-'.str_replace(':', '', $openTime);

        $data = new TwoDResultData(
            historyId: $historyId,
            stockDate: $live->date,
            stockDateTime: $live->dateTime,
            openTime: $this->normalizer->time($openTime),
            twod: $live->twod,
            setIndex: $live->set,
            value: $live->value,
            raw: $live->raw,
        );

        try {
            $this->writer->write($data);

            $this->warn("Live fallback persisted: history_id={$historyId}, twod={$live->twod}");

            return true;
        } catch (Throwable $e) {
            $this->error("Live fallback persist failed: {$e->getMessage()}");
            Log::error('twod:fetch-and-settle: live fallback persist failed', [
                'history_id' => $historyId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function settle(string $openTime, int $chunkSize): int
    {
        $today = now('Asia/Yangon')->toDateString();

        $result = TwoDResult::query()
            ->whereDate('stock_date', $today)
            ->where('open_time', 'like', $openTime.'%')
            ->latest('id')
            ->first();

        if ($result === null) {
            $this->error("No TwoDResult found for {$today} open_time={$openTime}. Skipping settlement.");

            return self::FAILURE;
        }

        try {
            $summary = $this->settlementService->settleTwoDResult($result, $chunkSize);
        } catch (Throwable $e) {
            $this->error('Settlement failed: '.$e->getMessage());
            Log::error('twod:fetch-and-settle settlement exception', [
                'history_id' => $result->history_id,
                'error' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }

        $this->info("Settlement — Settled: {$summary['settled']}, Won: {$summary['won']}, Lost: {$summary['lost']}, Skipped: {$summary['skipped']}");

        return self::SUCCESS;
    }
}
