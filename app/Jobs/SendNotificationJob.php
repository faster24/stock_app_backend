<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\FirebaseNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Escalating backoff — a transient FCM 5xx shouldn't cost the user a flat minute. */
    public array $backoff = [5, 30, 120];

    public function __construct(
        public User $user,
        public string $title,
        public string $body,
        public array $data = [],
        public string $notificationType = 'system'
    ) {
        $this->queue = 'notifications';
    }

    public function handle(FirebaseNotificationService $fcmService): void
    {
        $fcmService->sendToUser(
            $this->user,
            $this->title,
            $this->body,
            $this->data,
            $this->notificationType
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Notification job permanently failed for user {$this->user->id}: ".$exception->getMessage());
    }
}
