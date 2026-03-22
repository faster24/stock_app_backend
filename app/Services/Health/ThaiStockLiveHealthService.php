<?php

namespace App\Services\Health;

use App\Services\Service;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

class ThaiStockLiveHealthService extends Service
{
    private const URL = 'https://api.thaistock2d.com/live';

    public function checkThaiStock2dLive(): array
    {
        $checkedAt = Carbon::now()->toISOString();

        try {
            $response = Http::acceptJson()
                ->timeout(20)
                ->get(self::URL);
        } catch (Throwable $exception) {
            return [
                'service' => 'thaistock2d_live',
                'url' => self::URL,
                'healthy' => false,
                'upstream_status' => null,
                'checked_at' => $checkedAt,
                'reason' => 'Request failed: '.$exception->getMessage(),
            ];
        }

        $payload = $response->json();
        $hasValidResultArray = is_array($payload) && isset($payload['result']) && is_array($payload['result']);
        $healthy = $response->successful() && $hasValidResultArray;

        return [
            'service' => 'thaistock2d_live',
            'url' => self::URL,
            'healthy' => $healthy,
            'upstream_status' => $response->status(),
            'checked_at' => $checkedAt,
            'reason' => $healthy
                ? 'Upstream is healthy.'
                : 'Upstream is unhealthy: non-success status or invalid payload structure.',
        ];
    }
}
