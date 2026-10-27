<?php

declare(strict_types=1);

namespace AwaisJameel\DiditLaravelClient;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DiditLaravelClientServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('didit-laravel-client')
            ->hasConfigFile('didit-laravel-client');
    }

    /**
     * Bind the client as a singleton so the facade and container resolve a
     * single shared instance with the application's configuration.
     */
    public function packageRegistered(): void
    {
        $this->app->singleton(DiditLaravelClient::class, function () {
            return new DiditLaravelClient;
        });
    }
}
