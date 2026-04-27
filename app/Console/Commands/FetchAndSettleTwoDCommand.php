<?php

namespace App\Console\Commands;

use App\Models\TwoDResult;
use App\Services\Bet\BetSettlementService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchAndSettleTwoDCommand extends Command
{
    protected $signature = 'twod:fetch-and-settle
                            {open_time : The open_time slot to settle after fetch, e.g. "12:01"}
                            {--timeout-minutes=120 : Total minutes to keep retrying before giving up}
                            {--retry-interval=60 : Seconds to wait between retry attempts}
                            {--chunk-size=500 : Bet settlement chunk size}
                            {--no-live-fallback : Do not fall back to live data on timeout; just give up}';

    protected $description = 'Fetch 2D live results with time-based retry, then settle bets for the given open_time slot.';

    private const HTTP_TIMEOUT_SECONDS = 20;

    public function __construct(private readonly BetSettlementService $settlementService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $openTime = trim($this->argument('open_time'));
        $timeoutMinutes = max(1, (int) $this->option('timeout-minutes'));
        $retryInterval = max(10, (int) $this->option('retry-interval'));
        $chunkSize = max(1, (int) $this->option('chunk-size'));

        $fetched = $this->fetchWithRetry($openTime, $timeoutMinutes, $retryInterval);

        if ($fetched === null) {
            return self::FAILURE;
        }

        if ($fetched['type'] === 'live') {
            if (! $this->persistLiveFallback($fetched['payload']['live'], $openTime)) {
                return self::FAILURE;
            }
        } else {
            if (! $this->persistResults($fetched['payload'])) {
                return self::FAILURE;
            }
        }

        return $this->settle($openTime, $chunkSize);
    }

    private function fetchWithRetry(string $openTime, int $timeoutMinutes, int $retryInterval): ?array
    {
        $deadline = now()->addMinutes($timeoutMinutes);
        $attempt = 0;
        $lastPayload = null;

        while (true) {
            $attempt++;
            $remainingMinutes = (int) now()->diffInMinutes($deadline, false);
            $this->info("Attempt {$attempt}: fetching from thaistock2d... ({$remainingMinutes}m remaining)");

            $payload = $this->tryFetch();

            if ($payload !== null) {
                $lastPayload = $payload;

                $hasResult = collect($payload['result'] ?? [])->contains(
                    fn ($item) => str_starts_with($item['open_time'] ?? '', $openTime)
                        && ! empty($item['history_id'])
                        && ($item['twod'] ?? '--') !== '--'
                );

                if ($hasResult) {
                    $this->info("Fetch succeeded on attempt {$attempt}.");

                    return ['type' => 'result', 'payload' => $payload];
                }

                $this->info("Result for open_time={$openTime} not yet in payload.");
            }

            if (now()->greaterThanOrEqualTo($deadline)) {
                break;
            }

            $secondsLeft = (int) now()->diffInSeconds($deadline, false);
            $wait = min($retryInterval, $secondsLeft);

            $this->warn("Retrying in {$wait}s...");
            sleep($wait);
        }

        if (! $this->option('no-live-fallback') && $lastPayload !== null && ! empty($lastPayload['live']['twod'])) {
            $this->warn("Timeout reached after {$timeoutMinutes}m ({$attempt} attempts). Falling back to live data.");
            Log::warning('twod:fetch-and-settle: using live fallback', [
                'open_time' => $openTime,
                'attempts' => $attempt,
                'live' => $lastPayload['live'],
            ]);

            return ['type' => 'live', 'payload' => $lastPayload];
        }

        Log::critical('twod:fetch-and-settle timed out with no usable data.', [
            'open_time' => $openTime,
            'timeout_minutes' => $timeoutMinutes,
            'attempts' => $attempt,
        ]);
        $this->error("Timed out after {$timeoutMinutes} minutes ({$attempt} attempts). No live fallback available. Bets will NOT be settled.");

        return null;
    }

    private function tryFetch(): ?array
    {
        try {
            $response = Http::acceptJson()->timeout(self::HTTP_TIMEOUT_SECONDS)->get('https://api.thaistock2d.com/live');
        } catch (Throwable $e) {
            $this->error("Request exception: {$e->getMessage()}");

            return null;
        }

        if (! $response->successful()) {
            $this->error("HTTP {$response->status()} response.");

            return null;
        }

        $payload = $response->json();

        if (! is_array($payload) || (empty($payload['result']) && ! isset($payload['live']))) {
            $this->error('Invalid payload: no result array and no live data.');

            return null;
        }

        return $payload;
    }

    private function persistResults(array $payload): bool
    {
        $fetched = count($payload['result']);
        $saved = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($payload['result'] as $item) {
            if (! is_array($item)) {
                $failed++;

                continue;
            }

            $historyId = $this->normalizeString($item['history_id'] ?? null);

            if ($historyId === null) {
                $failed++;

                continue;
            }

            try {
                $result = TwoDResult::updateOrCreate(
                    ['history_id' => $historyId],
                    [
                        'stock_date' => $this->parseDate($item['stock_date'] ?? null),
                        'stock_datetime' => $this->parseDateTime($item['stock_datetime'] ?? null),
                        'open_time' => $this->parseTime($item['open_time'] ?? null),
                        'twod' => $this->normalizeString($item['twod'] ?? null),
                        'set_index' => $this->normalizeString($item['set'] ?? null),
                        'value' => $this->normalizeString($item['value'] ?? null),
                        'payload' => $item,
                    ]
                );

                if ($result->wasRecentlyCreated || $result->wasChanged()) {
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

        if ($failed === $fetched) {
            $this->error('All rows failed during persistence. Aborting settlement.');

            return false;
        }

        return true;
    }

    private function persistLiveFallback(array $live, string $openTime): bool
    {
        $date = $this->parseDate($live['date'] ?? null)
            ?? $this->parseDate($live['time'] ?? null);

        $historyId = '2d-live-'.($date ?? now('Asia/Yangon')->toDateString()).'-'.str_replace(':', '', $openTime);

        try {
            TwoDResult::updateOrCreate(
                ['history_id' => $historyId],
                [
                    'stock_date' => $date,
                    'stock_datetime' => $this->parseDateTime($live['time'] ?? null),
                    'open_time' => $this->parseTime($openTime),
                    'twod' => $this->normalizeString($live['twod'] ?? null),
                    'set_index' => $this->normalizeString($live['set'] ?? null),
                    'value' => $this->normalizeString($live['value'] ?? null),
                    'payload' => $live,
                ]
            );

            $this->warn("Live fallback persisted: history_id={$historyId}, twod={$live['twod']}");

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

    private function normalizeString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function parseDate(mixed $value): ?string
    {
        $normalized = $this->normalizeString($value);

        if ($normalized === null) {
            return null;
        }

        try {
            return Carbon::parse($normalized)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function parseDateTime(mixed $value): ?string
    {
        $normalized = $this->normalizeString($value);

        if ($normalized === null) {
            return null;
        }

        try {
            return Carbon::parse($normalized)->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }

    private function parseTime(mixed $value): ?string
    {
        $normalized = $this->normalizeString($value);

        if ($normalized === null) {
            return null;
        }

        try {
            return Carbon::parse($normalized)->format('H:i:s');
        } catch (Throwable) {
            return null;
        }
    }
}
