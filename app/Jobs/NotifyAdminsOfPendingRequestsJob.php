<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Admin\PendingRequestCountsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One aggregated "here is what is waiting for you" push per admin, rather than
 * one notification per incoming request. Scheduled with a delay by
 * ScheduleAdminPendingRequestsNotification, so a burst of requests collapses
 * into a single message carrying the totals.
 */
class NotifyAdminsOfPendingRequestsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Held by the listener for the length of the debounce window. */
    public const DEBOUNCE_CACHE_KEY = 'admin:pending-requests-push-scheduled';

    /** How long incoming requests are allowed to pile into one push. */
    public const DEBOUNCE_SECONDS = 120;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct()
    {
        $this->queue = 'notifications';
    }

    public function handle(PendingRequestCountsService $pendingRequestCounts): void
    {
        // Released first: anything arriving from here on belongs to the next
        // window and must be free to schedule its own push.
        Cache::forget(self::DEBOUNCE_CACHE_KEY);

        $counts = $pendingRequestCounts->counts();

        // Everything that triggered this window was already handled by an admin
        // before the delay elapsed — nothing worth waking anyone up for.
        if ($counts['total'] === 0) {
            return;
        }

        // Deliberately not User::role('admin') — that throws RoleDoesNotExist on
        // an install (or a test database) where the role has never been created,
        // and a notification must not be the thing that breaks a deposit.
        $admins = User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', 'admin'))
            ->whereHas('activeFcmTokens')
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

        $body = $this->describe($counts);

        foreach ($admins as $admin) {
            SendNotificationJob::dispatch(
                $admin,
                'Requests waiting',
                $body,
                [
                    'type' => 'admin_pending_requests',
                    'bets' => $counts['bets'],
                    'deposits' => $counts['deposits'],
                    'withdrawals' => $counts['withdrawals'],
                    'total' => $counts['total'],
                ],
                'admin_pending_requests'
            );
        }
    }

    /**
     * @param  array{bets: int, deposits: int, withdrawals: int, total: int}  $counts
     */
    private function describe(array $counts): string
    {
        $segments = [];

        // Zero-count queues are dropped rather than shown as "0 deposits".
        if ($counts['bets'] > 0) {
            $segments[] = $counts['bets'].' '.Str::plural('win', $counts['bets']).' to pay out';
        }

        if ($counts['deposits'] > 0) {
            $segments[] = $counts['deposits'].' '.Str::plural('deposit', $counts['deposits']);
        }

        if ($counts['withdrawals'] > 0) {
            $segments[] = $counts['withdrawals'].' '.Str::plural('withdrawal', $counts['withdrawals']);
        }

        return implode(', ', $segments).' waiting in the system.';
    }

    public function failed(\Throwable $exception): void
    {
        Cache::forget(self::DEBOUNCE_CACHE_KEY);

        Log::error('Admin pending-requests notification permanently failed: '.$exception->getMessage());
    }
}
