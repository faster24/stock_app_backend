<?php

namespace App\Services;

use LogicException;

abstract class Service
{
    protected function notImplemented(string $method): never
    {
        throw new LogicException($method.' is not implemented yet.');
    }
}
