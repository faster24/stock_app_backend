<?php

namespace App\Services\Health;

use App\Contracts\TwoDLiveProvider;
use App\Exceptions\TwoDProviderException;
use App\Services\Service;
use Illuminate\Support\Carbon;

class ThaiStockLiveHealthService extends Service
{
    public function __construct(private readonly TwoDLiveProvider $provider) {}

    public function checkThaiStock2dLive(): array
    {
        $checkedAt = Carbon::now()->toISOString();

        // The bound TwoDLiveProvider follows TWOD_DRIVER, so the reported URL
        // has to follow it too — reporting the thaistock2d endpoint while
        // actually probing htayapi describes a check that never ran.
        $driver = (string) config('services.twod.driver');
        $url = (string) config("services.twod.{$driver}.url");

        try {
            $snapshot = $this->provider->fetch();
        } catch (TwoDProviderException $exception) {
            return [
                'service' => 'thaistock2d_live',
                'driver' => $driver,
                'url' => $url,
                'healthy' => false,
                'upstream_status' => $exception->upstreamStatus(),
                'checked_at' => $checkedAt,
                'reason' => $exception->getMessage(),
            ];
        }

        $healthy = $snapshot->hasRecognisedPayload();

        return [
            'service' => 'thaistock2d_live',
            'driver' => $driver,
            'url' => $url,
            'healthy' => $healthy,
            'upstream_status' => $snapshot->upstreamStatus,
            'checked_at' => $checkedAt,
            'reason' => $healthy
                ? 'Upstream is healthy.'
                : 'Upstream is unhealthy: non-success status or invalid payload structure.',
        ];
    }
}
