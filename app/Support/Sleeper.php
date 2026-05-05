<?php

namespace App\Support;

interface Sleeper
{
    public function sleep(int $seconds): void;
}
