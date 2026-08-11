<?php

namespace App\Services\ThreeD;

use App\Contracts\ThreeDHistoryProvider;
use App\Exceptions\ThreeDProviderException;
use App\Services\TwoD\HtayApiCallBudget;
use App\Support\ThreeD\ThreeDDraw;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The htayapi.com implementation of {@see ThreeDHistoryProvider}.
 *
 * Shares HtayApi's single per-key daily quota with the 2D live ticker and
 * settlement, so it goes through the same {@see HtayApiCallBudget} and charges
 * pre-flight — a failed upstream call still costs a real quota unit. 3D draws
 * only twice a month, so this consumer's share of the budget is negligible as
 * long as ThreeDHistoryService's cache stays in front of it.
 */
class HtayApiThreeDHistoryProvider implements ThreeDHistoryProvider
{
    public function __construct(
        private readonly string $url,
        private readonly string $apiKey,
        private readonly int $timeout,
        private readonly HtayApiCallBudget $budget,
    ) {}

    public function fetch(): array
    {
        if (! $this->budget->tryConsume()) {
            throw new ThreeDProviderException('HtayApi daily call budget exhausted.');
        }

        try {
            $response = Http::acceptJson()
                ->timeout($this->timeout)
                ->withHeaders([
                    'X-HtayApi-Key' => $this->apiKey,
                    'X-HtayApi-Platform' => 'web',
                ])
                ->get($this->url, ['key' => $this->apiKey]);
        } catch (Throwable $exception) {
            throw new ThreeDProviderException('Request failed: '.$exception->getMessage(), null, $exception);
        }

        if (! $response->successful()) {
            throw new ThreeDProviderException("Upstream returned HTTP {$response->status()}.", $response->status());
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new ThreeDProviderException('Upstream payload was not a JSON object.', $response->status());
        }

        return $this->map($payload);
    }

    /**
     * Upstream shape:
     *   {"result": 1, "message": "success",
     *    "data": [{"result": "479", "datetime": "2026-08-01"}, ...]}
     *
     * Rows missing either field are dropped rather than rendered as blanks —
     * a partial list of real draws beats a list with holes in it.
     *
     * @param  array<mixed>  $payload
     * @return list<ThreeDDraw>
     */
    private function map(array $payload): array
    {
        $rows = $payload['data'] ?? null;

        if (! is_array($rows)) {
            throw new ThreeDProviderException('Upstream payload had no data array.');
        }

        $entries = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $threed = $row['result'] ?? null;
            $stockDate = $row['datetime'] ?? null;

            if (! is_scalar($threed) || ! is_scalar($stockDate)) {
                continue;
            }

            $threed = trim((string) $threed);
            $stockDate = trim((string) $stockDate);

            if ($threed === '' || $stockDate === '') {
                continue;
            }

            $entries[] = new ThreeDDraw($threed, $stockDate);
        }

        return $entries;
    }
}
