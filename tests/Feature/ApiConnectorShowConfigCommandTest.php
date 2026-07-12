<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Padosoft\AskMyDocsConnectorApi\Services\ConnectorAdminService;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;

/**
 * R44 PHP surface: `api-connector:show-config` prints the same canonical config
 * JSON the HTTP resource + FE modal use, tenant-scoped.
 */
final class ApiConnectorShowConfigCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prints_the_route_config_json(): void
    {
        $this->app->make(TenantContext::class)->set('acme');
        $service = $this->app->make(ConnectorAdminService::class);
        $connector = $service->createConnector(['name' => 'C1']);
        $route = $service->createRoute($connector, [
            'name' => 'List users',
            'http_method' => 'GET',
            'url' => 'https://api.example.com/users',
            'mode' => 'tool',
            'endpoint_type' => 'list',
            'items_path' => 'data',
            'parameters' => [['name' => 'q', 'location' => 'query', 'source' => 'llm', 'type' => 'string', 'required' => false]],
        ]);

        $exit = Artisan::call('api-connector:show-config', ['route' => $route->id, '--tenant' => 'acme']);
        $out = Artisan::output();

        $this->assertSame(0, $exit);
        $decoded = json_decode($out, true);
        $this->assertSame('List users', $decoded['identity']['name']);
        $this->assertSame('https://api.example.com/users', $decoded['request']['url']);
        $this->assertSame('list', $decoded['response']['endpoint_type']);
        $this->assertSame('q', $decoded['request']['params'][0]['name']);
    }

    public function test_a_route_from_another_tenant_is_not_found(): void
    {
        $this->app->make(TenantContext::class)->set('acme');
        $service = $this->app->make(ConnectorAdminService::class);
        $connector = $service->createConnector(['name' => 'C1']);
        $route = $service->createRoute($connector, [
            'name' => 'R', 'http_method' => 'GET', 'url' => 'https://x', 'mode' => 'tool', 'parameters' => [],
        ]);

        // Same id, wrong tenant → FAILURE (R30).
        $exit = Artisan::call('api-connector:show-config', ['route' => $route->id, '--tenant' => 'other']);

        $this->assertSame(1, $exit);
    }
}
