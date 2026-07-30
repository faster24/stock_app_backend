<?php

namespace App\Listeners\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Notification listeners run synchronously inside the admin's request so that
 * SendNotificationJob is queued in one hop instead of two. That means a throw
 * here would 500 the admin on an approval whose money is already committed —
 * a notification is never worth failing the caller for.
 */
trait NeverFailsTheCaller
{
    protected function withoutFailing(string $context, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Log::error("Failed to enqueue {$context} notification: ".$e->getMessage());
        }
    }
}
