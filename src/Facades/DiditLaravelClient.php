<?php

namespace AwaisJameel\DiditLaravelClient\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \AwaisJameel\DiditLaravelClient\DiditLaravelClient
 */
class DiditLaravelClient extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \AwaisJameel\DiditLaravelClient\DiditLaravelClient::class;
    }
}
