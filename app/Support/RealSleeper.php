<?php

namespace App\Support;

class RealSleeper implements Sleeper
{
    public function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}
