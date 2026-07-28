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
        $url = (string) config('services.twod.thaistock2d.url');

        try {
            $snapshot = $this->provider->fetch();
        } catch (TwoDProviderException $exception) {
            return [
                'service' => 'thaistock2d_live',
                'url' => $url,
                'healthy' => false,
                'upstream_status' => $exception->upstreamStatus(),
                'checked_at' => $checkedAt,
                'reason' => $exception->getMessage(),
            ];
        }

        $healthy = $snapshot->hasResultArray();

        return [
            'service' => 'thaistock2d_live',
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
