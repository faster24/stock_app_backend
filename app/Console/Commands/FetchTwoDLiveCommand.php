<?php

namespace App\Console\Commands;

use App\Models\TwoDResult;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

class FetchTwoDLiveCommand extends Command
{
    protected $signature = 'twod:fetch-live';

    protected $description = 'Fetch 2D live results and persist them.';

    public function handle(): int
    {
        try {
            $response = Http::acceptJson()
                ->timeout(20)
                ->get('https://api.thaistock2d.com/live');
        } catch (Throwable $exception) {
            $this->error('Request failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error("Request failed with status {$response->status()}.");

            return self::FAILURE;
        }

        $payload = $response->json();

        if (! is_array($payload) || ! isset($payload['result']) || ! is_array($payload['result'])) {
            $this->error('Invalid payload: missing result array.');

            return self::FAILURE;
        }

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

        $this->info("Fetched: {$fetched}");
        $this->info("Saved: {$saved}");
        $this->info("Skipped: {$skipped}");
        $this->info("Failed: {$failed}");

        if ($failed > 0) {
            $this->error('One or more rows failed during persistence.');

            return self::FAILURE;
        }

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
