<?php

namespace App\Support\TwoD;

/**
 * The live section of a 2D snapshot, used for the timeout fallback.
 *
 * {@see $time} is kept as the raw upstream datetime string because the caller
 * matches it against a settlement slot by hour; the other fields are normalized.
 */
final class TwoDLiveData
{
    public function __construct(
        public readonly ?string $twod,
        public readonly ?string $time,
        public readonly ?string $date,
        public readonly ?string $dateTime,
        public readonly ?string $set,
        public readonly ?string $value,
        public readonly array $raw,
    ) {}
}
