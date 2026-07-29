<?php

namespace App\Support\TwoD;

use App\Services\TwoD\HtayApiFreshnessGuard;
use Illuminate\Support\Carbon;

/**
 * Maps a raw HtayApi payload into a normalized {@see TwoDLiveSnapshot}.
 *
 * HtayApi's shape (`morning`/`evening` objects, each with modern/internet/2d/key
 * variants, plus an unrelated `taiwan` block) has nothing in common with
 * thaistock2d's `result[]`/`live{}` shape, so this is an independent mapper
 * rather than a reuse of {@see TwoDSnapshotMapper} — only the `"2d"` field is
 * the authoritative settlement number; `modern`/`internet`/`key`/`taiwan` are
 * never read.
 *
 * `stockDate`/`historyId` are self-anchored on this system's own current
 * Asia/Bangkok date rather than trusting HtayApi's own top-level `"date"`
 * field, mirroring SetIndexProvider's pattern and sidestepping that field's
 * ambiguous semantics entirely.
 */
class HtayApiSnapshotMapper
{
    /** @var array<string, string> slot label => settlement open_time */
    private const SLOTS = [
        'morning' => '12:01',
        'evening' => '16:30',
    ];

    public function __construct(
        private readonly TwoDPayloadNormalizer $normalizer,
        private readonly HtayApiFreshnessGuard $freshnessGuard,
    ) {}

    public function map(array $payload, int $upstreamStatus): TwoDLiveSnapshot
    {
        $date = Carbon::now('Asia/Bangkok')->toDateString();
        $results = [];
        $recognisedBlocks = 0;

        foreach (self::SLOTS as $label => $openTime) {
            $block = $payload[$label] ?? null;

            if (! is_array($block)) {
                continue;
            }

            $recognisedBlocks++;

            $twod = $this->normalizer->string($block['2d'] ?? null);

            if ($twod === null || $twod === '--') {
                continue;
            }

            if (! $this->freshnessGuard->isFresh($openTime, $twod)) {
                continue;
            }

            $results[] = new TwoDResultData(
                historyId: "htayapi-{$date}-{$label}",
                stockDate: $date,
                stockDateTime: null,
                openTime: $openTime,
                twod: $twod,
                setIndex: null,
                value: null,
                raw: $block,
            );
        }

        return new TwoDLiveSnapshot(
            upstreamStatus: $upstreamStatus,
            results: $results,
            live: null,
            raw: $payload,
            // Shape is judged on the slot blocks being present, independently of
            // whether their values passed the freshness guard — a correctly
            // shaped payload read before publication is healthy, just not usable
            // yet.
            payloadRecognised: $recognisedBlocks > 0,
        );
    }
}
