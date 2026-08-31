<?php

namespace App\Services\TwoD;

use App\Support\TwoD\HtayApiSnapshotMapper;
use App\Support\TwoD\TwoDSnapshotMapper;
use Illuminate\Support\Manager;

/**
 * Resolves the configured {@see \App\Contracts\TwoDLiveProvider} driver.
 *
 * The active driver is read from `services.twod.driver`. Adding a new vendor is a
 * matter of adding a `create<Name>Driver()` method plus a config block — no
 * consumer of the provider needs to change.
 */
class TwoDLiveProviderManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('services.twod.driver');
    }

    protected function createThaistock2dDriver(): ThaiStock2dProvider
    {
        $config = $this->config->get('services.twod.thaistock2d', []);

        return new ThaiStock2dProvider(
            (string) ($config['url'] ?? ''),
            (int) ($config['timeout'] ?? 20),
            $this->container->make(TwoDSnapshotMapper::class),
        );
    }

    protected function createHtayapiDriver(): HtayApiProvider
    {
        $config = $this->config->get('services.twod.htayapi', []);

        return new HtayApiProvider(
            (string) ($config['url'] ?? ''),
            (string) ($config['key'] ?? ''),
            (int) ($config['timeout'] ?? 20),
            new HtayApiCallBudget((int) ($config['daily_limit'] ?? 25)),
            $this->container->make(HtayApiSnapshotMapper::class),
        );
    }
}
