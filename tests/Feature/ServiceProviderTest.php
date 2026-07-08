<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Padosoft\AskMyDocsConnectorApi\ApiConnectorServiceProvider;
use Padosoft\AskMyDocsConnectorApi\Auth\AuthApplierFactory;
use Padosoft\AskMyDocsConnectorApi\Support\UrlGuard;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;

/**
 * Boot-time contract of {@see ApiConnectorServiceProvider}:
 * migrations create the 5 tables, config is merged, the two Artisan commands are
 * registered (R44 PHP surface), the admin routes mount, and the shared
 * singletons resolve.
 */
final class ServiceProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_five_api_tables_are_created(): void
    {
        foreach ([
            'api_connectors',
            'api_auth_profiles',
            'api_routes',
            'api_route_parameters',
            'api_tool_call_logs',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_package_config_is_merged(): void
    {
        $this->assertSame(16384, config('connector-api.output.max_bytes'));
        $this->assertSame(16, config('connector-api.tools.max_per_conversation'));
        $this->assertIsBool(config('connector-api.chat_tools.enabled'));
    }

    public function test_both_artisan_commands_are_registered(): void
    {
        $commands = array_keys(Artisan::all());

        $this->assertContains('api-connector:list', $commands);
        $this->assertContains('api-connector:test', $commands);
    }

    public function test_admin_routes_are_registered_under_the_default_prefix(): void
    {
        $this->assertTrue(Route::has('api-connectors.index'));
        $this->assertTrue(Route::has('api-connectors.store'));
        $this->assertTrue(Route::has('api-connectors.routes.test'));

        $route = Route::getRoutes()->getByName('api-connectors.index');
        $this->assertNotNull($route);
        $this->assertSame('api/admin/api-connectors', $route->uri());
    }

    public function test_shared_singletons_resolve(): void
    {
        $this->assertInstanceOf(UrlGuard::class, $this->app->make(UrlGuard::class));
        $this->assertInstanceOf(AuthApplierFactory::class, $this->app->make(AuthApplierFactory::class));
        // Singleton identity.
        $this->assertSame($this->app->make(UrlGuard::class), $this->app->make(UrlGuard::class));
    }
}
