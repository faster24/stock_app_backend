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
     * @param  bool  $payloadRecognised  Whether the upstream payload matched the
     *                                   producing provider's expected shape. Set
     *                                   by whoever builds the snapshot, since
     *                                   only they know that provider's contract.
     */
    public function __construct(
        public readonly int $upstreamStatus,
        public readonly array $results,
        public readonly ?TwoDLiveData $live,
        public readonly array $raw,
        public readonly bool $payloadRecognised = true,
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
     * Whether the upstream payload matched the provider's expected shape.
     *
     * Used by the health check and `twod:fetch-live` to distinguish a
     * structurally valid response (even with zero rows) from an unexpected
     * payload shape. This deliberately does NOT inspect `raw` itself: the
     * previous implementation looked for a thaistock2d-style `result` array,
     * which no other provider sends, so every htayapi response was reported
     * unhealthy regardless of its actual content.
     */
    public function hasRecognisedPayload(): bool
    {
        return $this->payloadRecognised;
    }
}
