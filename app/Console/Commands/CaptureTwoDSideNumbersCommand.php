<?php

namespace App\Console\Commands;

use App\Enums\TwoDSideSlot;
use App\Services\TwoD\TwoDSideNumberCaptureService;
use App\Support\Sleeper;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Captures one HtayApi side slot's modern/internet pair. Stores only — this
 * command can never settle a bet.
 */
class CaptureTwoDSideNumbersCommand extends Command
{
    protected $signature = 'twod:capture-side-numbers
                            {slot : One of morning (09:30 MMT) or evening (14:00 MMT)}
                            {--date= : Result date (Y-m-d) in the market timezone; defaults to today}
                            {--max-attempts=3 : Hard cap on upstream fetches; each spends one HtayApi daily budget unit}
                            {--retry-interval=300 : Seconds to wait between attempts}
                            {--force : Re-capture even if a complete row already exists}';

    protected $description = 'Fetch and store the modern/internet numbers for one 2D side slot.';

    /**
     * Skip reasons that no amount of retrying will change. Retrying these only
     * burns the shared HtayApi budget the settlement scheduler depends on.
     */
    private const TERMINAL_REASONS = ['non_trading_day', 'driver_unsupported', 'already_captured'];

    public function __construct(
        private readonly TwoDSideNumberCaptureService $captureService,
        private readonly Sleeper $sleeper,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $slot = TwoDSideSlot::tryFrom((string) $this->argument('slot'));

        if ($slot === null) {
            $valid = implode(', ', array_map(fn (TwoDSideSlot $s) => $s->value, TwoDSideSlot::cases()));
            $this->error("Invalid slot '{$this->argument('slot')}'. Valid: {$valid}");

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

        $maxAttempts = max(1, (int) $this->option('max-attempts'));
        $retryInterval = max(1, (int) $this->option('retry-interval'));
        $force = (bool) $this->option('force');

        $summary = [];
        $attemptsExhausted = false;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $this->info("Attempt {$attempt}/{$maxAttempts}: capturing {$slot->value} side numbers...");

            $summary = $this->captureService->capture($slot, $date, $force);

            if ($summary['status'] === 'stored') {
                break;
            }

            if (in_array($summary['reason'] ?? '', self::TERMINAL_REASONS, true)) {
                break;
            }

            if ($attempt < $maxAttempts) {
                $this->warn("Retrying in {$retryInterval}s...");
                $this->sleeper->sleep($retryInterval);

                continue;
            }

            // Fell off the end of the loop still not stored, on a reason that
            // retrying was supposed to fix. The slot is now lost for the day.
            $attemptsExhausted = true;
        }

        return $this->report($summary, $attemptsExhausted, $maxAttempts);
    }

    /**
     * @param  array{status: string, slot: string, result_date: string, modern?: ?string, internet?: ?string, reason?: string, message?: string}  $summary
     */
    private function report(array $summary, bool $attemptsExhausted, int $maxAttempts): int
    {
        $where = "{$summary['slot']} @ {$summary['result_date']}";

        if ($summary['status'] === 'stored') {
            $modern = $summary['modern'] ?? '--';
            $internet = $summary['internet'] ?? '--';
            $this->info("Stored {$where}: modern={$modern} internet={$internet}.");

            return self::SUCCESS;
        }

        $reason = $summary['reason'] ?? 'unknown';

        // A skip is a legitimate outcome — holidays, pre-publication reads and
        // already-captured days all land here. Exiting non-zero would make the
        // scheduler log look like an incident every weekend.
        $this->warn("Skipped {$where}: {$reason}.");

        // ...but running out of retries on a reason retrying was meant to fix is
        // NOT legitimate: that day's numbers are gone, and upstream serves no
        // history to backfill them from. Before this, the only trace was an
        // untimestamped line in scheduler.log indistinguishable from a holiday
        // skip, which is how 2026-08-20/21 lost two full days unnoticed. Exit
        // code stays 0 so the scheduler does not retry a lost cause; the log
        // line is the alert.
        if ($attemptsExhausted) {
            Log::error("2D side numbers lost for {$where}: gave up after {$maxAttempts} attempt(s) on '{$reason}'.", [
                'slot' => $summary['slot'],
                'result_date' => $summary['result_date'],
                'reason' => $reason,
                'attempts' => $maxAttempts,
                'upstream_message' => $summary['message'] ?? null,
            ]);
        }

        return self::SUCCESS;
    }
}
