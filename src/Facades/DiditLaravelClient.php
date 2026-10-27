<?php

declare(strict_types=1);

namespace AwaisJameel\DiditLaravelClient\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @mixin \AwaisJameel\DiditLaravelClient\DiditLaravelClient
 *
 * @see \AwaisJameel\DiditLaravelClient\DiditLaravelClient
 */
class DiditLaravelClient extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \AwaisJameel\DiditLaravelClient\DiditLaravelClient::class;
    }
}
