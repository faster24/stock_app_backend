<?php

namespace App\Services;

use App\Models\FcmToken;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FirebaseNotificationService extends Service
{
    private $messaging = null;

    private function messaging()
    {
        if ($this->messaging === null) {
            $credentialsPath = base_path(config('firebase.credentials_path', 'firebase-key.json'));

            if (! file_exists($credentialsPath)) {
                throw new \RuntimeException("Firebase credentials not found at: {$credentialsPath}");
            }

            $this->messaging = (new Factory)->withServiceAccount($credentialsPath)->createMessaging();
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

        $success = true;
        foreach ($tokens as $token) {
            try {
                $this->sendToToken($token, $title, $body, $data);
            } catch (\Throwable $e) {
                Log::error("FCM send failed for user {$user->id}: ".$e->getMessage());
                $success = false;
            }
        }

        $this->logNotification(
            $user->id,
            $title,
            $body,
            $notificationType,
            $data,
            $success ? 'sent' : 'failed'
        );

        return $success;
    }

    public function sendToToken(string $token, string $title, string $body, array $data = []): void
    {
        $message = CloudMessage::new()
            ->withToken($token)
            ->withNotification(Notification::create($title, $body))
            ->withData($data);

        $this->messaging()->send($message);

        FcmToken::where('token', $token)->update(['last_used_at' => now()]);
    }

    public function sendToTopic(string $topic, string $title, string $body, array $data = []): void
    {
        $message = CloudMessage::new()
            ->withTopic($topic)
            ->withNotification(Notification::create($title, $body))
            ->withData($data);

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
