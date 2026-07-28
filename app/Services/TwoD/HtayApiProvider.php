<?php

namespace App\Services\TwoD;

use App\Contracts\TwoDLiveProvider;
use App\Exceptions\TwoDProviderException;
use App\Support\TwoD\HtayApiSnapshotMapper;
use App\Support\TwoD\TwoDLiveSnapshot;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The htayapi.com implementation of {@see TwoDLiveProvider}.
 *
 * HtayApi's test key is capped at 100 requests/day, so every call is gated by
 * {@see HtayApiCallBudget} BEFORE the network request is attempted — a failed
 * upstream call still consumes a real quota unit when it happens, so budget
 * is charged pre-flight rather than only on success. This is the single choke
 * point for every consumer of this driver (scheduled settlement, manual admin
 * re-fetch, the health-check service), since they all resolve the same bound
 * TwoDLiveProvider::class.
 */
class HtayApiProvider implements TwoDLiveProvider
{
    public function __construct(
        private readonly string $url,
        private readonly string $apiKey,
        private readonly int $timeout,
        private readonly HtayApiCallBudget $budget,
        private readonly HtayApiSnapshotMapper $mapper,
    ) {}

    public function fetch(): TwoDLiveSnapshot
    {
        if (! $this->budget->tryConsume()) {
            throw new TwoDProviderException('HtayApi daily call budget exhausted.');
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
            throw new TwoDProviderException('Request failed: '.$exception->getMessage(), null, $exception);
        }

        if (! $response->successful()) {
            throw new TwoDProviderException("Upstream returned HTTP {$response->status()}.", $response->status());
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new TwoDProviderException('Upstream payload was not a JSON object.', $response->status());
        }

        return $this->mapper->map($payload, $response->status());
    }
}
