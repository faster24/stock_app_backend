<?php

namespace App\Console\Commands;

use App\Contracts\TwoDLiveProvider;
use App\Exceptions\TwoDProviderException;
use App\Services\TwoD\TwoDResultWriter;
use Illuminate\Console\Command;
use Throwable;

class FetchTwoDLiveCommand extends Command
{
    protected $signature = 'twod:fetch-live';

    protected $description = 'Fetch 2D live results and persist them.';

    public function __construct(
        private readonly TwoDLiveProvider $provider,
        private readonly TwoDResultWriter $writer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $snapshot = $this->provider->fetch();
        } catch (TwoDProviderException $exception) {
            $this->error('Request failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! $snapshot->hasResultArray()) {
            $this->error('Invalid payload: missing result array.');

            return self::FAILURE;
        }

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
}
