<?php

namespace App\Services;

use App\Models\FcmToken;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Http\HttpClientOptions;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FirebaseNotificationService extends Service
{
    private $messaging = null;

    /**
     * Building the messaging client performs a Google OAuth token exchange over
     * the network. This is memoized per instance, and the service is bound as a
     * singleton in AppServiceProvider — so a long-lived queue worker pays that
     * cost once per process rather than once per notification.
     */
    private function messaging()
    {
        if ($this->messaging === null) {
            $credentialsPath = base_path(config('firebase.credentials_path', 'firebase-key.json'));

            if (! file_exists($credentialsPath)) {
                throw new \RuntimeException("Firebase credentials not found at: {$credentialsPath}");
            }

            $this->messaging = (new Factory)
                ->withServiceAccount($credentialsPath)
                // Bounded so a hung FCM call can't consume the worker's whole
                // job timeout. Both must stay well under queue:work --timeout.
                ->withHttpClientOptions(
                    HttpClientOptions::default()
                        ->withConnectTimeout(3.0)
                        ->withReadTimeout(10.0)
                )
                ->createMessaging();
        }

        return $this->messaging;
    }

    public function sendToUser(
        User $user,
        string $title,
        string $body,
        array $data = [],
        string $notificationType = 'system'
    ): bool {
        $tokens = $user->activeFcmTokens()->pluck('token')->toArray();

        if (empty($tokens)) {
            Log::warning("No active FCM tokens for user {$user->id}");
            $this->logNotification($user->id, $title, $body, $notificationType, $data, 'failed', 'No active tokens');

            return false;
        }

        $message = CloudMessage::new()
            ->withNotification(Notification::create($title, $body))
            // FCM rejects non-string data values.
            ->withData(array_map(strval(...), $data))
            ->withHighestPossiblePriority();

        try {
            // One multicast request for every device, rather than one blocking
            // round trip per token in series.
            $report = $this->messaging()->sendMulticast($message, $tokens);
        } catch (\Throwable $e) {
            Log::error("FCM send failed for user {$user->id}: ".$e->getMessage());
            $this->logNotification($user->id, $title, $body, $notificationType, $data, 'failed', $e->getMessage());

            return false;
        }

        // FCM tokens rotate. Without this, dead tokens stay active forever and
        // burn a failed delivery attempt on every subsequent notification.
        $deadTokens = array_merge($report->unknownTokens(), $report->invalidTokens());
        if ($deadTokens !== []) {
            FcmToken::whereIn('token', $deadTokens)->update(['is_active' => false]);
            Log::info('Deactivated '.count($deadTokens)." dead FCM token(s) for user {$user->id}");
        }

        $validTokens = $report->validTokens();
        if ($validTokens !== []) {
            FcmToken::whereIn('token', $validTokens)->update(['last_used_at' => now()]);
        }

        $success = $validTokens !== [];

        $this->logNotification(
            $user->id,
            $title,
            $body,
            $notificationType,
            $data,
            $success ? 'sent' : 'failed',
            $success ? null : 'No token accepted the message'
        );

        return $success;
    }

    public function sendToTopic(string $topic, string $title, string $body, array $data = []): void
    {
        $message = CloudMessage::new()
            ->withTopic($topic)
            ->withNotification(Notification::create($title, $body))
            ->withData($data);

        $this->messaging()->send($message);
    }

    public function sendDataToTopic(string $topic, array $data): void
    {
        // Data-only (no notification block) so clients handle it silently;
        // highest priority so Android delivers it while the app is backgrounded.
        $message = CloudMessage::new()
            ->withTopic($topic)
            ->withData(array_map(strval(...), $data))
            ->withHighestPossiblePriority();

        $this->messaging()->send($message);
    }

    public function subscribeToTopic(string $token, string $topic): void
    {
        $this->messaging()->subscribeToTopic($topic, [$token]);
    }

    public function unsubscribeFromTopic(string $token, string $topic): void
    {
        $this->messaging()->unsubscribeFromTopic($topic, [$token]);
    }

    private function logNotification(
        string $userId,
        string $title,
        string $body,
        string $notificationType,
        array $data,
        string $status,
        ?string $errorMessage = null
    ): NotificationLog {
        return NotificationLog::create([
            'user_id' => $userId,
            'title' => $title,
            'body' => $body,
            'notification_type' => $notificationType,
            'data' => $data,
            'status' => $status,
            'error_message' => $errorMessage,
            'sent_at' => now(),
        ]);
    }
}
