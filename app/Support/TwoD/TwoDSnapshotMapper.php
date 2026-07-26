<?php

namespace App\Support\TwoD;

/**
 * Maps a raw thaistock2d-style payload into a normalized {@see TwoDLiveSnapshot}.
 *
 * Kept separate from transport so it can be reused by any provider and exercised
 * in isolation. A vendor with a different JSON shape supplies its own mapper (or
 * translates into this one) without any consumer change.
 */
class TwoDSnapshotMapper
{
    public function __construct(private readonly TwoDPayloadNormalizer $normalizer) {}

    public function map(array $payload, int $upstreamStatus): TwoDLiveSnapshot
    {
        return new TwoDLiveSnapshot(
            upstreamStatus: $upstreamStatus,
            results: $this->mapResults($payload['result'] ?? []),
            live: $this->mapLive($payload['live'] ?? null),
            raw: $payload,
        );
    }

    /**
     * @return TwoDResultData[]
     */
    private function mapResults(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $results = [];

        foreach ($rows as $item) {
            if (! is_array($item)) {
                continue;
            }

            $historyId = $this->normalizer->string($item['history_id'] ?? null);

            // A row without a history_id cannot key the idempotency log, so it
            // is not a usable result — skip it.
            if ($historyId === null) {
                continue;
            }

            $results[] = new TwoDResultData(
                historyId: $historyId,
                stockDate: $this->normalizer->date($item['stock_date'] ?? null),
                stockDateTime: $this->normalizer->dateTime($item['stock_datetime'] ?? null),
                openTime: $this->normalizer->time($item['open_time'] ?? null),
                twod: $this->normalizer->string($item['twod'] ?? null),
                setIndex: $this->normalizer->string($item['set'] ?? null),
                value: $this->normalizer->string($item['value'] ?? null),
                raw: $item,
            );
        }

        return $results;
    }

    private function mapLive(mixed $live): ?TwoDLiveData
    {
        if (! is_array($live)) {
            return null;
        }

        return new TwoDLiveData(
            twod: $this->normalizer->string($live['twod'] ?? null),
            time: $this->normalizer->string($live['time'] ?? null),
            date: $this->normalizer->date($live['date'] ?? null)
                ?? $this->normalizer->date($live['time'] ?? null),
            dateTime: $this->normalizer->dateTime($live['time'] ?? null),
            set: $this->normalizer->string($live['set'] ?? null),
            value: $this->normalizer->string($live['value'] ?? null),
            raw: $live,
        );
    }
}
