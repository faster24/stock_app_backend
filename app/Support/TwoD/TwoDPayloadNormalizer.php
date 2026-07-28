<?php

namespace App\Support\TwoD;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Coerces raw upstream 2D fields into normalized, storable strings.
 *
 * Extracted verbatim from the fetch commands so mapping lives in exactly one
 * place and can be shared by every provider.
 */
class TwoDPayloadNormalizer
{
    public function string(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    public function date(mixed $value): ?string
    {
        $normalized = $this->string($value);

        if ($normalized === null) {
            return null;
        }

        try {
            return Carbon::parse($normalized)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    public function dateTime(mixed $value): ?string
    {
        $normalized = $this->string($value);

        if ($normalized === null) {
            return null;
        }

        try {
            return Carbon::parse($normalized)->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }

    public function time(mixed $value): ?string
    {
        $normalized = $this->string($value);

        if ($normalized === null) {
            return null;
        }

        try {
            return Carbon::parse($normalized)->format('H:i:s');
        } catch (Throwable) {
            return null;
        }
    }
}
