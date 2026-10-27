<?php

namespace AwaisJameel\DiditLaravelClient\Tests;

use AwaisJameel\DiditLaravelClient\DiditLaravelClientServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app)
    {
        return [
            DiditLaravelClientServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('didit-laravel-client', [
            'api_key' => 'test-api-key',
            'base_url' => 'https://verification.didit.me',
            'webhook_secret' => 'test-webhook-secret',
            'timeout' => 10,
            'debug' => false,
        ]);
    }
}
