<?php

namespace App\Services\Auth;

use App\Exceptions\TooManySecurityPinAttemptsException;
use App\Models\User;
use App\Services\Service;
use DomainException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class SecurityPinVerifier extends Service
{
    /**
     * Failed attempts are counted, not requests: a user who types the right PIN
     * every time is never throttled, so this can sit on the money paths without
     * blocking legitimate traffic the way route-level `throttle` would.
     */
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public const RESET_REQUIRED_MESSAGE = 'Your security PIN must be reset. Please contact support.';

    public function assertValid(User $user, string $pin, string $notSetMessage): void
    {
        if ($user->security_pin === null) {
            throw new DomainException($notSetMessage);
        }

        // Until this class grew setForUser() there was no way to change a PIN,
        // so the only way to help a locked-out player was editing the column by
        // hand — and a PIN written there in plaintext fails Hash::check forever,
        // which reads as "my correct PIN is rejected" and is invisible in the
        // logs. Say so explicitly instead. Never compare the plaintext: a
        // hand-written PIN must not be a working credential.
        if (! Hash::isHashed($user->security_pin)) {
            Log::warning('Stored security PIN is not hashed.', ['user_id' => $user->id]);

            throw new DomainException(self::RESET_REQUIRED_MESSAGE);
        }

        $key = $this->throttleKey($user);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw new TooManySecurityPinAttemptsException(RateLimiter::availableIn($key));
        }

        if (! Hash::check($pin, $user->security_pin)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'security_pin' => ['Invalid security PIN.'],
            ]);
        }

        RateLimiter::clear($key);
    }

    /**
     * The single writer for a security PIN. Registration, the player-facing
     * change endpoint and the admin reset all come through here so hashing can
     * never be forgotten on one path — and so a lockout has an answer that is
     * not "edit the database".
     */
    public function setForUser(User $user, string $pin): void
    {
        $user->forceFill([
            // Explicit, though the model's `hashed` cast would also do it:
            // relying on the cast alone means silently storing plaintext the day
            // someone removes it, and this column is a credential.
            'security_pin' => Hash::make($pin),
            'security_pin_set_at' => now(),
        ])->save();

        // A new PIN starts with a clean slate; otherwise a player who forgot
        // theirs, burned the five attempts and then reset it would still be
        // locked out for the rest of the minute by the old counter.
        RateLimiter::clear($this->throttleKey($user));
    }

    private function throttleKey(User $user): string
    {
        // One PIN per user, so bets and withdrawals share a single counter.
        return 'security-pin:'.$user->id;
    }
}
