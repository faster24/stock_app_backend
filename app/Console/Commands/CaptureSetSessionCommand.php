<?php

namespace App\Console\Commands;

use App\Enums\SetSession;
use App\Exceptions\SetScraperException;
use App\Services\Set\SetSessionCaptureService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class CaptureSetSessionCommand extends Command
{
    protected $signature = 'set:capture
                            {session : One of morning_open, morning_close, afternoon_open, evening_close}
                            {--date= : Result date (Y-m-d) in the market timezone; defaults to today}
                            {--force : Re-capture even if a stabilized row already exists}';

    protected $description = 'Scrape the SET index for a session, calculate the Myanmar 2D, and store it.';

    public function __construct(private readonly SetSessionCaptureService $captureService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $session = SetSession::tryFrom((string) $this->argument('session'));

        if ($session === null) {
            $valid = implode(', ', array_map(fn (SetSession $s) => $s->value, SetSession::cases()));
            $this->error("Invalid session '{$this->argument('session')}'. Valid: {$valid}");

            return self::FAILURE;
        }

        $timezone = (string) config('set.timezone', 'Asia/Bangkok');
        $dateOption = $this->option('date');

        try {
            $date = $dateOption
                ? Carbon::parse((string) $dateOption, $timezone)
                : Carbon::now($timezone);
        } catch (Throwable $e) {
            $this->error("Invalid --date value: {$e->getMessage()}");

            return self::FAILURE;
        }

        try {
            $summary = $this->captureService->capture($session, $date, (bool) $this->option('force'));
        } catch (SetScraperException $e) {
            $this->error("Capture failed for {$session->value}: {$e->getMessage()}");

            // Catching the exception here means nothing else ever reports it.
            // Console output only reaches set-capture.log, which nobody reads, so
            // a broken scraper would let the SET feed go stale in silence.
            Log::error("set:capture failed for {$session->value}: {$e->getMessage()}", [
                'session' => $session->value,
                'result_date' => $date->toDateString(),
            ]);

            return self::FAILURE;
        }

        return $this->report($summary);
    }

    /**
     * @param  array{status: string, session: string, result_date: string, two_d?: string, stabilized?: bool, reason?: string}  $summary
     */
    private function report(array $summary): int
    {
        $where = "{$summary['session']} @ {$summary['result_date']}";

        if ($summary['status'] === 'stored') {
            $stable = ($summary['stabilized'] ?? false) ? 'stable' : 'UNSTABLE';
            $this->info("Stored {$where}: 2D={$summary['two_d']} ({$stable}).");

            return self::SUCCESS;
        }

        $reason = $summary['reason'] ?? 'unknown';

        if ($reason === 'no_data') {
            $this->error("No usable data for {$where}. Nothing stored.");

            // Weekends and holidays arrive as an ordinary skip reason below, not
            // as no_data, so reaching here on a scheduled run means the scrape
            // succeeded but the page no longer holds what we parse.
            Log::error("set:capture found no usable data for {$where}.", $summary);

            return self::FAILURE;
        }

        $this->warn("Skipped {$where}: {$reason}.");

        return self::SUCCESS;
    }
}
