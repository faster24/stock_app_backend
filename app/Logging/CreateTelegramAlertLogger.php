<?php

namespace App\Logging;

use Monolog\Handler\NullHandler;
use Monolog\Level;
use Monolog\Logger;

/**
 * Factory for the `telegram` log channel (config/logging.php).
 *
 * Falls back to a NullHandler when the bot credentials are absent, so the
 * channel can sit in LOG_STACK unconditionally — local and CI simply discard
 * what production sends to the chat, with no env-specific stack to maintain.
 */
class CreateTelegramAlertLogger
{
    public function __invoke(array $config): Logger
    {
        $botToken = $config['bot_token'] ?? null;
        $chatId = $config['chat_id'] ?? null;

        if (blank($botToken) || blank($chatId)) {
            return new Logger('telegram', [new NullHandler]);
        }

        return new Logger('telegram', [
            new TelegramAlertHandler(
                botToken: $botToken,
                chatId: (string) $chatId,
                environment: $config['environment'] ?? 'unknown',
                throttleSeconds: (int) ($config['throttle_seconds'] ?? 300),
                timeoutSeconds: (int) ($config['timeout'] ?? 5),
                level: Level::fromName($config['level'] ?? 'error'),
            ),
        ]);
    }
}
