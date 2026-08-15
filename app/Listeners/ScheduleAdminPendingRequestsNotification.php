<?php

namespace App\Listeners;

use App\Jobs\NotifyAdminsOfPendingRequestsJob;
use App\Listeners\Concerns\NeverFailsTheCaller;
use Illuminate\Support\Facades\Cache;

/**
 * Fans in from every event that puts something on an admin's plate — a new
 * deposit, a new withdrawal, a settled winner awaiting payout — and schedules
 * at most one aggregated push per debounce window.
 */
class ScheduleAdminPendingRequestsNotification
{
    use NeverFailsTheCaller;

    public function handle(object $event): void
    {
        $context = 'admin_pending_requests ('.class_basename($event).')';

        $this->withoutFailing($context, function () {
            // Cache::add is atomic, so a burst of requests — including ones
            // racing across processes — schedules exactly one job. The job
            // releases the key when it runs, opening the next window.
            $scheduled = Cache::add(
                NotifyAdminsOfPendingRequestsJob::DEBOUNCE_CACHE_KEY,
                true,
                NotifyAdminsOfPendingRequestsJob::DEBOUNCE_SECONDS
            );

            if (! $scheduled) {
                return;
            }

            NotifyAdminsOfPendingRequestsJob::dispatch()
                ->delay(now()->addSeconds(NotifyAdminsOfPendingRequestsJob::DEBOUNCE_SECONDS))
                ->afterCommit();
        });
    }
}
