<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Feature;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorApi\Services\ConnectorAdminService;
use Padosoft\AskMyDocsConnectorApi\Support\EndpointType;
use Padosoft\AskMyDocsConnectorApi\Support\ParamSource;
use Padosoft\AskMyDocsConnectorApi\Support\RouteStatus;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use RuntimeException;

/**
 * The write-side core ({@see ConnectorAdminService}) is the ONE place all three
 * surfaces (HTTP / CLI / MCP) delegate to. Covers: tenant auto-scoping on
 * create, the R28 project_key-change guard, the param_mapping build, and the
 * "test before activate" status gate.
 */
final class ConnectorAdminServiceTest extends TestCase
{
    use RefreshDatabase;

    private ConnectorAdminService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(TenantContext::class)->set('acme');
        $this->service = $this->app->make(ConnectorAdminService::class);
    }

    public function test_create_connector_is_scoped_to_the_active_tenant(): void
    {
        $connector = $this->service->createConnector(['name' => 'C1', 'project_key' => 'proj-a']);

        $this->assertTrue($connector->exists);
        $this->assertSame('acme', $connector->tenant_id);
        $this->assertSame('proj-a', $connector->project_key);
        $this->assertDatabaseHas('api_connectors', ['name' => 'C1', 'tenant_id' => 'acme']);
    }

    public function test_find_connector_is_tenant_scoped(): void
    {
        $connector = $this->service->createConnector(['name' => 'C1']);

        // Switching tenant hides the row (R30) — findOrFail 404s.
        $this->app->make(TenantContext::class)->set('globex');
        $globexService = $this->app->make(ConnectorAdminService::class);

        $this->expectException(ModelNotFoundException::class);
        $globexService->findConnector($connector->id);
    }

    public function test_project_key_change_is_rejected_while_the_connector_owns_routes(): void
    {
        $connector = $this->service->createConnector(['name' => 'C1', 'project_key' => 'proj-a']);
        $this->service->createRoute($connector, [
            'name' => 'List things',
            'http_method' => 'GET',
            'url' => 'https://api.example.com/things',
            'mode' => 'tool',
            'parameters' => [],
        ]);

        try {
            $this->service->updateConnector($connector->fresh(), ['project_key' => 'proj-b']);
            $this->fail('Expected the R28 guard to reject the project_key change.');
        } catch (RuntimeException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertStringContainsString('project_key', $e->getMessage());
        }
    }

    public function test_create_route_builds_param_mapping_and_starts_as_draft(): void
    {
        $connector = $this->service->createConnector(['name' => 'C1', 'project_key' => 'proj-a']);

        $route = $this->service->createRoute($connector, [
            'name' => 'Search',
            'http_method' => 'GET',
            'url' => 'https://api.example.com/search',
            'mode' => 'tool',
            'parameters' => [
                ['name' => 'q', 'location' => 'query', 'source' => 'llm', 'type' => 'string', 'required' => true],
                ['name' => 'api_key', 'location' => 'query', 'source' => 'fixed', 'type' => 'string', 'value' => 'K'],
            ],
        ]);

        $this->assertSame(RouteStatus::Draft, $route->status);
        // project_key denormalised from the connector (R28).
        $this->assertSame('proj-a', $route->project_key);
        $this->assertSame('acme', $route->tenant_id);
        $this->assertCount(2, $route->parameters);

        $mapping = $route->param_mapping;
        $this->assertSame(['location' => 'query', 'source' => ParamSource::Llm->value], $mapping['q']);
        $this->assertSame(
            ['location' => 'query', 'source' => ParamSource::Fixed->value, 'value' => 'K'],
            $mapping['api_key'],
        );
    }

    public function test_activate_requires_a_tested_route(): void
    {
        $connector = $this->service->createConnector(['name' => 'C1']);
        $route = $this->service->createRoute($connector, [
            'name' => 'Search',
            'http_method' => 'GET',
            'url' => 'https://api.example.com/search',
            'mode' => 'tool',
            'parameters' => [],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);
        $this->service->activateRoute($route);
    }

    public function test_delete_connector_returns_cleanly(): void
    {
        $connector = $this->service->createConnector(['name' => 'C1']);

        $this->service->deleteConnector($connector);

        $this->assertDatabaseMissing('api_connectors', ['id' => $connector->id]);
    }

    public function test_test_route_auto_detects_the_endpoint_taxonomy(): void
    {
        Http::fake([
            'api.example.com/*' => Http::response(['data' => [['id' => 1], ['id' => 2]]], 200),
        ]);
        $connector = $this->service->createConnector(['name' => 'C1']);
        $route = $this->service->createRoute($connector, [
            'name' => 'List users',
            'http_method' => 'GET',
            'url' => 'https://api.example.com/users',
            'mode' => 'tool',
            'parameters' => [],
        ]);

        $tested = $this->service->testRoute($route, [])['route'];

        $this->assertSame(RouteStatus::Tested, $tested->status);
        $this->assertSame(EndpointType::List, $tested->endpoint_type);
        $this->assertSame('data', $tested->items_path);
        $this->assertFalse($tested->endpoint_type_locked);
        $this->assertDatabaseHas('api_routes', [
            'id' => $route->id,
            'endpoint_type' => 'list',
            'items_path' => 'data',
        ]);
    }

    public function test_test_route_preserves_a_locked_operator_override(): void
    {
        // Operator forced 'detail' up front; the live response is a list, but the
        // detector must NOT clobber the locked choice.
        Http::fake([
            'api.example.com/*' => Http::response([['id' => 1], ['id' => 2]], 200),
        ]);
        $connector = $this->service->createConnector(['name' => 'C1']);
        $route = $this->service->createRoute($connector, [
            'name' => 'Odd one',
            'http_method' => 'GET',
            'url' => 'https://api.example.com/thing',
            'mode' => 'tool',
            'endpoint_type' => 'detail',
            'parameters' => [],
        ]);
        $this->assertTrue($route->endpoint_type_locked);
        $this->assertSame(EndpointType::Detail, $route->endpoint_type);

        $tested = $this->service->testRoute($route, [])['route'];

        $this->assertSame(EndpointType::Detail, $tested->endpoint_type);
        $this->assertTrue($tested->endpoint_type_locked);
    }
}
