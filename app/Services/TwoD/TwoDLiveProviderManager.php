<?php

namespace App\Services\TwoD;

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

    /**
     * SET-index-derived provider. Reads set_session_results (populated by the
     * scheduled set:capture command); it never scrapes on the settlement path.
     */
    protected function createSetDriver(): SetIndexProvider
    {
        return $this->container->make(SetIndexProvider::class);
    }
}
