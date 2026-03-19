<?php

namespace App\Console\Commands;

use App\Models\TwoDResult;
use App\Services\Bet\BetSettlementService;
use DomainException;
use Illuminate\Console\Command;

class SettleTwoDCommand extends Command
{
    protected $signature = 'bets:settle-2d {history_id} {--chunk-size=500}';

    protected $description = 'Settle accepted 2D bets for a specific result history.';

    public function __construct(private readonly BetSettlementService $betSettlementService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $historyId = trim((string) $this->argument('history_id'));
        $chunkSize = (int) $this->option('chunk-size');

        $result = TwoDResult::query()
            ->where('history_id', $historyId)
            ->first();

        if ($result === null) {
            $this->error("TwoDResult not found for history_id [{$historyId}].");

            return self::FAILURE;
        }

        try {
            $summary = $this->betSettlementService->settleTwoDResult($result, $chunkSize);
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("History ID: {$historyId}");
        $this->info('Settled: '.$summary['settled']);
        $this->info('Won: '.$summary['won']);
        $this->info('Lost: '.$summary['lost']);
        $this->info('Skipped: '.$summary['skipped']);

        return self::SUCCESS;
    }
}
