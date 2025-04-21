<?php

namespace AwaisJameel\DiditLaravelClient\Tests;

use AwaisJameel\DiditLaravelClient\DiditLaravelClientServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
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
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'base_url' => 'https://verification.didit.me',
            'auth_url' => 'https://apx.didit.me',
            'webhook_secret' => 'test-webhook-secret',
            'timeout' => 10,
            'token_expiry_buffer' => 300,
            'debug' => false,
        ]);
    }
}