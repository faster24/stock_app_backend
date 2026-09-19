<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * A bet touching a closed ("break") number or one past its sales limit.
 *
 * Still a ValidationException, so the 422 keeps its `errors.bet_numbers`
 * sentences for older clients; the structured list rides along in `data` so
 * a client can tell the player exactly which numbers to take off the slip.
 */
class BetNumbersUnavailableException extends ValidationException
{
    public const CODE = 'BET_NUMBERS_UNAVAILABLE';

    /**
     * @var list<array{number: string, reason: 'closed'|'limit_reached', remaining: string|null}>
     */
    private array $unavailableNumbers = [];

    /**
     * @param  list<array{number: string, reason: 'closed'|'limit_reached', remaining: string|null}>  $unavailable
     * @param  list<string>  $messages
     */
    public static function forNumbers(array $unavailable, array $messages): self
    {
        $exception = static::withMessages(['bet_numbers' => $messages]);
        $exception->unavailableNumbers = $unavailable;

        return $exception;
    }

    /**
     * @return list<array{number: string, reason: 'closed'|'limit_reached', remaining: string|null}>
     */
    public function unavailableNumbers(): array
    {
        return $this->unavailableNumbers;
    }
}
