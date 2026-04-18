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
                            {--max-attempts=6 : Maximum fetch attempts before giving up}
                            {--chunk-size=500 : Bet settlement chunk size}';

    protected $description = 'Fetch 2D live results with retry backoff, then settle bets for the given open_time slot.';

    // Seconds to wait before each retry attempt (index 0 = before attempt 2, etc.)
    private const RETRY_DELAYS = [60, 120, 300, 600, 900];

    public function __construct(private readonly BetSettlementService $settlementService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $openTime = trim($this->argument('open_time'));
        $maxAttempts = max(1, (int) $this->option('max-attempts'));
        $chunkSize = max(1, (int) $this->option('chunk-size'));

        $payload = $this->fetchWithRetry($maxAttempts);

        if ($payload === null) {
            return self::FAILURE;
        }

        if (! $this->persistResults($payload)) {
            return self::FAILURE;
        }

        return $this->settle($openTime, $chunkSize);
    }

    private function fetchWithRetry(int $maxAttempts): ?array
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($attempt > 1) {
                $delay = self::RETRY_DELAYS[$attempt - 2] ?? end(self::RETRY_DELAYS);
                $this->warn("Attempt {$attempt}/{$maxAttempts}: waiting {$delay}s before retry...");
                sleep($delay);
            }

            $this->info("Attempt {$attempt}/{$maxAttempts}: fetching from thaistock2d...");

            try {
                $response = Http::acceptJson()->timeout(20)->get('https://api.thaistock2d.com/live');
            } catch (Throwable $e) {
                $this->error("Request exception: {$e->getMessage()}");
                $this->logRetryOrExhausted($attempt, $maxAttempts);

                continue;
            }

            if (! $response->successful()) {
                $this->error("HTTP {$response->status()} response.");
                $this->logRetryOrExhausted($attempt, $maxAttempts);

                continue;
            }

            $payload = $response->json();

            if (! is_array($payload) || ! isset($payload['result']) || ! is_array($payload['result']) || $payload['result'] === []) {
                $this->error('Invalid or empty payload: missing result array.');
                $this->logRetryOrExhausted($attempt, $maxAttempts);

                continue;
            }

            $this->info('Fetch succeeded on attempt '.$attempt.'.');

            return $payload;
        }

        Log::critical('twod:fetch-and-settle exhausted all attempts. No settlement will run.', [
            'max_attempts' => $maxAttempts,
        ]);
        $this->error('All fetch attempts exhausted. Bets will NOT be settled.');

        return null;
    }

    private function logRetryOrExhausted(int $attempt, int $maxAttempts): void
    {
        if ($attempt < $maxAttempts) {
            $this->warn("Will retry (attempt {$attempt}/{$maxAttempts} failed).");
        }
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
            $this->error('One or more rows failed during persistence.');

            return false;
        }

        return true;
    }

    private function settle(string $openTime, int $chunkSize): int
    {
        $today = now('Asia/Bangkok')->toDateString();

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
