<?php

namespace App\Support\TwoD;

/**
 * A normalized, vendor-neutral view of one 2D upstream response.
 *
 * Consumers (settlement command, fetch command, health check) depend on this
 * shape rather than any provider's raw JSON, so a provider can be swapped
 * without touching them.
 */
final class TwoDLiveSnapshot
{
    /**
     * @param  TwoDResultData[]  $results
     */
    public function __construct(
        public readonly int $upstreamStatus,
        public readonly array $results,
        public readonly ?TwoDLiveData $live,
        public readonly array $raw,
    ) {}

    /**
     * Whether a finalized result for the given slot is present.
     *
     * Mirrors the settlement success predicate: a result row whose open_time
     * starts with the slot and whose 2D number is finalized (not "--").
     * Rows without a history_id are already excluded during mapping.
     */
    public function hasResultFor(string $openTime): bool
    {
        foreach ($this->results as $result) {
            if ($result->openTime !== null
                && str_starts_with($result->openTime, $openTime)
                && $result->twod !== null
                && $result->twod !== '--') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the upstream payload carried a `result` array at all.
     *
     * Used by the health check to distinguish a structurally valid response
     * (even with zero rows) from an unexpected payload shape.
     */
    public function hasResultArray(): bool
    {
        return array_key_exists('result', $this->raw) && is_array($this->raw['result']);
    }
}
