<?php

namespace App\Logging;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Throwable;

/**
 * Pushes error-level log records to a Telegram chat.
 *
 * Deliberately synchronous and dependency-light. The obvious alternative —
 * queueing the send — would make the alert path depend on the queue worker,
 * which is one of the things most likely to be broken when an alert fires.
 * The whole point is that this keeps working when the rest does not.
 *
 * Two consequences of sending inline, both handled below: it must never throw
 * (a failed alert cannot be allowed to fail the request that triggered it) and
 * it must never flood (one hot loop should not fire thousands of messages).
 */
class TelegramAlertHandler extends AbstractProcessingHandler
{
    /** Telegram rejects anything longer; leave room for the header we prepend. */
    private const MAX_MESSAGE_LENGTH = 3500;

    public function __construct(
        private readonly string $botToken,
        private readonly string $chatId,
        private readonly string $environment,
        private readonly int $throttleSeconds,
        private readonly int $timeoutSeconds,
        int|string|\Monolog\Level $level = \Monolog\Level::Error,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        try {
            if (! $this->shouldSend($record)) {
                return;
            }

            Http::timeout($this->timeoutSeconds)
                ->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", [
                    'chat_id' => $this->chatId,
                    'text' => $this->format($record),
                    'disable_web_page_preview' => true,
                ]);
        } catch (Throwable $exception) {
            // Never Log:: from here — this handler is attached to the log stack,
            // so reporting a delivery failure through it would recurse. PHP's
            // own error log is the one channel guaranteed not to come back here.
            error_log('TelegramAlertHandler failed to deliver: '.$exception->getMessage());
        }
    }

    /**
     * One message per distinct problem per throttle window.
     *
     * A broken endpoint under load emits the same record hundreds of times a
     * minute. Without this, the first incident buries every later one and the
     * chat gets muted — which is a worse outcome than no alerting at all.
     */
    private function shouldSend(LogRecord $record): bool
    {
        if ($this->throttleSeconds <= 0) {
            return true;
        }

        $fingerprint = sha1($record->level->getName().'|'.mb_substr($record->message, 0, 200));

        try {
            // Cache::add is atomic: the first caller in the window wins, the
            // rest are dropped.
            return Cache::add('telegram-alert:'.$fingerprint, true, $this->throttleSeconds);
        } catch (Throwable $exception) {
            // The throttle is a convenience; the alert is the point. A cache
            // outage is itself the kind of incident worth hearing about, so
            // fail open and accept the risk of duplicates.
            return true;
        }
    }

    private function format(LogRecord $record): string
    {
        $lines = [
            "🚨 {$this->environment} — {$record->level->getName()}",
            $record->datetime->format('Y-m-d H:i:s T'),
            '',
            $record->message,
        ];

        if ($record->context !== []) {
            $context = json_encode(
                $record->context,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR,
            );

            if (is_string($context)) {
                $lines[] = '';
                $lines[] = $context;
            }
        }

        return mb_strimwidth(implode("\n", $lines), 0, self::MAX_MESSAGE_LENGTH, "\n… (truncated)");
    }
}
