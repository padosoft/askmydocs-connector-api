<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;

/**
 * R43 OFF-path: with `connector-api.routes.enabled=false` the admin routes are
 * simply NOT registered — a clean degrade, no exception, and the rest of the
 * package (config) still loads. A feature flag must be safe in BOTH states.
 */
final class RoutesDisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Set BEFORE the provider's mergeConfigFrom runs so this override wins.
        $app['config']->set('connector-api.routes.enabled', false);
    }

    public function test_admin_routes_are_not_registered_when_disabled(): void
    {
        $this->assertFalse(Route::has('api-connectors.index'));
        $this->assertFalse(Route::has('api-connectors.store'));
        $this->assertFalse(Route::has('api-connectors.routes.test'));
    }

    public function test_package_still_boots_and_config_is_intact(): void
    {
        // The OFF path must not break the app: config is still merged.
        $this->assertSame(16384, config('connector-api.output.max_bytes'));
        $this->assertFalse(config('connector-api.routes.enabled'));
    }
}
