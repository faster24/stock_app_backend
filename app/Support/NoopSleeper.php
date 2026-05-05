<?php

namespace App\Support;

class NoopSleeper implements Sleeper
{
    public function sleep(int $seconds): void {}
}
