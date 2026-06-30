<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\AskMyDocsConnectorApi\ApiConnectorServiceProvider;
use Padosoft\AskMyDocsConnectorBase\ConnectorServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ConnectorServiceProvider::class,
            ApiConnectorServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.cipher', 'AES-256-CBC');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        // Deterministic SSRF behaviour in tests: keep the guard ON but skip DNS
        // resolution (fake hosts don't resolve) and allow http so Http::fake
        // targets like http://api.example.test pass. UrlGuard's own unit test
        // exercises the strict prod policy (https-only + DNS + private blocks).
        $app['config']->set('connector-api.ssrf.enabled', true);
        $app['config']->set('connector-api.ssrf.https_only', false);
        $app['config']->set('connector-api.ssrf.resolve_dns', false);
        $app['config']->set('connector-api.ssrf.allowlist', []);
    }
}
