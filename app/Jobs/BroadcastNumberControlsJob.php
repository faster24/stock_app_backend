<?php

namespace App\Jobs;

use App\Services\FirebaseNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BroadcastNumberControlsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public string $betType,
        public string $currency,
        public string $targetOpentime,
        public string $stockDate,
    ) {
        $this->queue = 'notifications';
    }

    public function handle(FirebaseNotificationService $fcmService): void
    {
        $fcmService->sendDataToTopic($this->topicName(), [
            'type' => 'number_controls_updated',
            'bet_type' => $this->betType,
            'currency' => $this->currency,
            'target_opentime' => $this->targetOpentime,
            'stock_date' => $this->stockDate,
            'updated_at' => now('Asia/Bangkok')->toIso8601String(),
        ]);
    }

    public function topicName(): string
    {
        return strtolower("number-controls-{$this->betType}-{$this->currency}");
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Number controls broadcast permanently failed for topic {$this->topicName()}: ".$exception->getMessage());
    }
}
