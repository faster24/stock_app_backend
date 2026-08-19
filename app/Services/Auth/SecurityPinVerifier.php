<?php

namespace App\Services\Auth;

use App\Exceptions\TooManySecurityPinAttemptsException;
use App\Models\User;
use App\Services\Service;
use DomainException;
use Illuminate\Support\Facades\Hash;
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

    public function assertValid(User $user, string $pin, string $notSetMessage): void
    {
        if ($user->security_pin === null) {
            throw new DomainException($notSetMessage);
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

    private function throttleKey(User $user): string
    {
        // One PIN per user, so bets and withdrawals share a single counter.
        return 'security-pin:'.$user->id;
    }
}
