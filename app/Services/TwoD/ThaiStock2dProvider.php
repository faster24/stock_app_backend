<?php

namespace App\Services\TwoD;

use App\Contracts\TwoDLiveProvider;
use App\Exceptions\TwoDProviderException;
use App\Support\TwoD\TwoDLiveSnapshot;
use App\Support\TwoD\TwoDSnapshotMapper;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The thaistock2d.com implementation of {@see TwoDLiveProvider}.
 *
 * This is the only place the thaistock2d URL and response shape live. It handles
 * transport and delegates payload mapping to {@see TwoDSnapshotMapper}.
 */
class ThaiStock2dProvider implements TwoDLiveProvider
{
    public function __construct(
        private readonly string $url,
        private readonly int $timeout,
        private readonly TwoDSnapshotMapper $mapper,
    ) {}

    public function fetch(): TwoDLiveSnapshot
    {
        try {
            $response = Http::acceptJson()->timeout($this->timeout)->get($this->url);
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
