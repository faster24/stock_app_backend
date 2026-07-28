<?php

namespace App\Support\TwoD;

/**
 * A single, vendor-neutral 2D result row.
 *
 * Fields are already normalized (dates/times parsed, strings trimmed) so the
 * persistence layer can store them without re-parsing. The original upstream
 * row is preserved in {@see $raw} and stored as the result payload.
 */
final class TwoDResultData
{
    public function __construct(
        public readonly string $historyId,
        public readonly ?string $stockDate,
        public readonly ?string $stockDateTime,
        public readonly ?string $openTime,
        public readonly ?string $twod,
        public readonly ?string $setIndex,
        public readonly ?string $value,
        public readonly array $raw,
    ) {}
}
