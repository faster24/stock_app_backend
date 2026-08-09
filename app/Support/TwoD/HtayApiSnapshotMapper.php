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
 * HtayApi does carry a `live{}` block, but its keys differ from thaistock2d's:
 * the ticking number is `live.live` (not `live.twod`) and the SET value is
 * `live.val` (not `live.value`). See {@see mapLive()}.
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
                // HtayApi carries no per-slot timestamp, so this is the slot's
                // own instant — matching how thaistock2d rows store it (an
                // open_time of 12:01 against a stock_datetime of 12:01:0x).
                // Leaving it null sorted every htayapi row beneath every
                // thaistock2d row, because MySQL orders NULLs last on DESC.
                stockDateTime: "{$date} {$openTime}:00",
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
            live: $this->mapLive($payload),
            raw: $payload,
            // Shape is judged on the slot blocks being present, independently of
            // whether their values passed the freshness guard — a correctly
            // shaped payload read before publication is healthy, just not usable
            // yet.
            payloadRecognised: $recognisedBlocks > 0,
        );
    }

    /**
     * Maps HtayApi's `live{}` block into the vendor-neutral {@see TwoDLiveData}.
     *
     * The key names do not line up with thaistock2d's, so this cannot reuse
     * {@see TwoDSnapshotMapper::mapLive()}:
     *
     *   twod  <- live.live   (thaistock2d: live.twod)
     *   set   <- live.set
     *   value <- live.val    (thaistock2d: live.value)
     *
     * There is no per-`live` timestamp either, so all three time fields derive
     * from the payload's top-level `date` ("2026-08-09 16:59:54 +0630"). `time`
     * stays the raw string, matching TwoDSnapshotMapper's contract that callers
     * may match it against a slot by hour.
     *
     * Unlike the settlement blocks, this is a preview value: it is deliberately
     * NOT run through {@see HtayApiFreshnessGuard}, because the ticker is
     * expected to hold the last known number outside market hours.
     */
    private function mapLive(array $payload): ?TwoDLiveData
    {
        $live = $payload['live'] ?? null;

        if (! is_array($live)) {
            return null;
        }

        $rawDate = $payload['date'] ?? null;

        return new TwoDLiveData(
            twod: $this->placeholderAware($live['live'] ?? null),
            time: $this->normalizer->string($rawDate),
            date: $this->normalizer->date($rawDate),
            dateTime: $this->normalizer->dateTime($rawDate),
            set: $this->placeholderAware($live['set'] ?? null),
            value: $this->placeholderAware($live['val'] ?? null),
            raw: $live,
        );
    }

    /**
     * Normalizes a field that may carry a "not available" placeholder.
     *
     * HtayApi fills the live block with `"??"` while the market is closed (and
     * `"--"` appears elsewhere in the feed). Both mean absent, but
     * {@see TwoDPayloadNormalizer::string()} only strips empty strings, so they
     * would otherwise reach the client as literal values to render.
     */
    private function placeholderAware(mixed $value): ?string
    {
        $normalized = $this->normalizer->string($value);

        if ($normalized === null || $normalized === '??' || $normalized === '--') {
            return null;
        }

        return $normalized;
    }
}
