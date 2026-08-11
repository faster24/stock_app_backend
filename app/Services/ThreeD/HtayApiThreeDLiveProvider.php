<?php

namespace App\Services\ThreeD;

use App\Contracts\ThreeDLiveProvider;
use App\Exceptions\ThreeDProviderException;
use App\Services\TwoD\HtayApiCallBudget;
use App\Support\ThreeD\ThreeDDraw;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The htayapi.com implementation of {@see ThreeDLiveProvider}.
 *
 * Shares HtayApi's single per-key daily quota with the 2D live ticker, the 3D
 * history feed, and settlement, so it goes through the same
 * {@see HtayApiCallBudget} and charges pre-flight — a failed upstream call still
 * costs a real quota unit.
 */
class HtayApiThreeDLiveProvider implements ThreeDLiveProvider
{
    public function __construct(
        private readonly string $url,
        private readonly string $apiKey,
        private readonly int $timeout,
        private readonly HtayApiCallBudget $budget,
    ) {}

    public function fetch(): ?ThreeDDraw
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
     * Upstream shape — note it differs from the history feed, which wraps a list
     * under `result`/`message`:
     *   {"code": 200, "data": {"date": "2026-08-01", "threed": "479"}}
     *
     * A missing or half-filled `data` means the vendor has nothing to publish yet
     * rather than a fault, so it returns null instead of raising.
     *
     * @param  array<mixed>  $payload
     */
    private function map(array $payload): ?ThreeDDraw
    {
        $data = $payload['data'] ?? null;

        if (! is_array($data)) {
            return null;
        }

        $threed = $data['threed'] ?? null;
        $stockDate = $data['date'] ?? null;

        if (! is_scalar($threed) || ! is_scalar($stockDate)) {
            return null;
        }

        $threed = trim((string) $threed);
        $stockDate = trim((string) $stockDate);

        if ($threed === '' || $stockDate === '') {
            return null;
        }

        return new ThreeDDraw($threed, $stockDate);
    }
}
