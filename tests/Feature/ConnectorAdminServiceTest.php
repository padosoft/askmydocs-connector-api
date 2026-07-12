<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Feature;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorApi\Contracts\ResponseAnalyst;
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

    public function test_analyze_route_reduces_a_long_response_without_persisting_or_ai(): void
    {
        Http::fake([
            'api.example.com/*' => Http::response(
                ['data' => array_map(fn (int $i): array => ['id' => $i, 'name' => "n{$i}"], range(1, 50))],
                200,
            ),
        ]);

        $connector = $this->service->createConnector(['name' => 'C1']);
        $route = $this->service->createRoute($connector, [
            'name' => 'Catalog',
            'http_method' => 'GET',
            'url' => 'https://api.example.com/catalog',
            'mode' => 'tool',
            'parameters' => [],
        ]);

        $out = $this->service->analyzeRoute($route, []);

        // The `data` collection is truncated to 3 items + a sentinel; structure kept.
        $this->assertCount(4, $out['reduced']['data']);
        $this->assertSame(['id' => 1, 'name' => 'n1'], $out['reduced']['data'][0]);
        $this->assertSame('data', $out['notes'][0]['path']);
        $this->assertSame(50, $out['notes'][0]['total']);
        // With the default Null analyst there is no AI narration, and dryRun did
        // NOT persist last_test_*.
        $this->assertNull($out['analysis']);
        $this->assertNull($route->fresh()->last_test_at);
    }

    public function test_analyze_route_adds_ai_narration_when_an_analyst_is_bound(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['data' => [['id' => 1]]], 200)]);
        $this->app->bind(ResponseAnalyst::class, fn (): ResponseAnalyst => new class implements ResponseAnalyst
        {
            public function analyze(array $context): ?string
            {
                return 'A collection of items lives under `data`.';
            }

            public function detectPagination(array $context): ?array
            {
                return null;
            }

            public function suggestConfiguration(array $context): ?array
            {
                return null;
            }
        });
        $service = $this->app->make(ConnectorAdminService::class);
        $connector = $service->createConnector(['name' => 'C1']);
        $route = $service->createRoute($connector, [
            'name' => 'Catalog', 'http_method' => 'GET',
            'url' => 'https://api.example.com/catalog', 'mode' => 'tool', 'parameters' => [],
        ]);

        $out = $service->analyzeRoute($route, []);

        $this->assertSame('A collection of items lives under `data`.', $out['analysis']);
    }

    public function test_analyze_route_skips_the_ai_when_llm_assist_is_disabled(): void
    {
        config()->set('connector-api.llm_assist.enabled', false);
        Http::fake(['api.example.com/*' => Http::response(['data' => [['id' => 1]]], 200)]);
        // An analyst that would throw if called — proves the gate short-circuits it.
        $this->app->bind(ResponseAnalyst::class, fn (): ResponseAnalyst => new class implements ResponseAnalyst
        {
            public function analyze(array $context): ?string
            {
                throw new RuntimeException('analyst must not be called when llm_assist is off');
            }

            public function detectPagination(array $context): ?array
            {
                return null;
            }

            public function suggestConfiguration(array $context): ?array
            {
                return null;
            }
        });
        $service = $this->app->make(ConnectorAdminService::class);
        $connector = $service->createConnector(['name' => 'C1']);
        $route = $service->createRoute($connector, [
            'name' => 'Catalog', 'http_method' => 'GET',
            'url' => 'https://api.example.com/catalog', 'mode' => 'tool', 'parameters' => [],
        ]);

        $out = $service->analyzeRoute($route, []);

        $this->assertNull($out['analysis']);
    }

    public function test_detect_pagination_finds_a_page_param_from_the_url(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['data' => [['id' => 1]]], 200)]);
        $connector = $this->service->createConnector(['name' => 'C1']);
        $route = $this->service->createRoute($connector, [
            'name' => 'List', 'http_method' => 'GET',
            'url' => 'https://api.example.com/list?page=1&per_page=10', 'mode' => 'tool', 'parameters' => [],
        ]);

        $out = $this->service->detectPagination($route, []);

        $this->assertSame('heuristic', $out['source']);
        $this->assertSame('page', $out['config']['type']);
        $this->assertSame('page', $out['config']['page_param']);
        $this->assertSame('per_page', $out['config']['size_param']);
    }

    public function test_test_pagination_page_detects_distinct_pages(): void
    {
        Http::fake(['api.example.com/*' => Http::sequence()
            ->push(['data' => [['id' => 1], ['id' => 2]]], 200)
            ->push(['data' => [['id' => 3], ['id' => 4]]], 200)]);
        $connector = $this->service->createConnector(['name' => 'C1']);
        $route = $this->service->createRoute($connector, [
            'name' => 'List', 'http_method' => 'GET',
            'url' => 'https://api.example.com/list', 'mode' => 'tool', 'parameters' => [],
        ]);

        $out = $this->service->testPagination($route, [
            'type' => 'page', 'page_param' => 'page', 'start_page' => 1, 'items_path' => 'data',
        ], []);

        $this->assertTrue($out['distinct']);
        $this->assertCount(2, $out['pages']);
        $this->assertSame(2, $out['pages'][0]['item_count']);
        $this->assertStringContainsString('funziona', $out['note']);
    }

    public function test_test_pagination_cursor_reports_a_missing_cursor(): void
    {
        // Body carries no cursor at the configured path → honest "not found" verdict.
        Http::fake(['api.example.com/*' => Http::response(['data' => [['id' => 1]]], 200)]);
        $connector = $this->service->createConnector(['name' => 'C1']);
        $route = $this->service->createRoute($connector, [
            'name' => 'List', 'http_method' => 'GET',
            'url' => 'https://api.example.com/list', 'mode' => 'tool', 'parameters' => [],
        ]);

        $out = $this->service->testPagination($route, [
            'type' => 'cursor', 'cursor_param' => 'cursor', 'next_cursor_path' => 'meta.next_cursor', 'items_path' => 'data',
        ], []);

        $this->assertFalse($out['distinct']);
        $this->assertStringContainsString('Cursore non trovato', $out['note']);
    }

    public function test_update_route_persists_the_pagination_config(): void
    {
        $connector = $this->service->createConnector(['name' => 'C1']);
        $route = $this->service->createRoute($connector, [
            'name' => 'List', 'http_method' => 'GET',
            'url' => 'https://api.example.com/list', 'mode' => 'tool', 'parameters' => [],
        ]);

        $this->service->updateRoute($route, ['pagination' => ['type' => 'page', 'page_param' => 'page']]);

        $this->assertSame(['type' => 'page', 'page_param' => 'page'], $route->fresh()->pagination);
    }

    public function test_test_search_fires_the_route_with_the_search_args(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['data' => [['id' => 1]]], 200)]);
        $connector = $this->service->createConnector(['name' => 'C1']);
        $route = $this->service->createRoute($connector, [
            'name' => 'Search', 'http_method' => 'GET', 'url' => 'https://api.example.com/search', 'mode' => 'tool',
            'parameters' => [['name' => 'q', 'location' => 'query', 'source' => 'llm', 'type' => 'string', 'required' => true]],
        ]);

        $result = $this->service->testSearch($route, ['q' => 'shoes']);

        $this->assertTrue($result->ok);
        Http::assertSent(fn (Request $req): bool => str_contains($req->url(), 'q=shoes'));
    }

    public function test_auto_configure_merges_deterministic_detection_with_ai_suggestions(): void
    {
        Http::fake(['api.example.com/*' => Http::response(
            ['data' => [['id' => 1, 'name' => 'x']], 'meta' => ['next_cursor' => 'c2']],
            200,
        )]);
        $this->app->bind(ResponseAnalyst::class, fn (): ResponseAnalyst => new class implements ResponseAnalyst
        {
            public function analyze(array $context): ?string
            {
                return null;
            }

            public function detectPagination(array $context): ?array
            {
                return null;
            }

            public function suggestConfiguration(array $context): ?array
            {
                return [
                    'tool_name' => 'list_catalog',
                    'tool_description' => 'List the catalog.',
                    'parameters' => [['name' => 'q', 'location' => 'query', 'source' => 'llm', 'type' => 'string', 'required' => false]],
                ];
            }
        });
        $service = $this->app->make(ConnectorAdminService::class);
        $connector = $service->createConnector(['name' => 'C1']);
        $route = $service->createRoute($connector, [
            'name' => 'Catalog', 'http_method' => 'GET',
            'url' => 'https://api.example.com/catalog', 'mode' => 'tool', 'parameters' => [],
        ]);

        $s = $service->autoConfigure($route, [])['suggestion'];

        // Deterministic: a list under `data` + cursor pagination from meta.next_cursor.
        $this->assertSame('list', $s['endpoint_type']);
        $this->assertSame('data', $s['items_path']);
        $this->assertSame('cursor', $s['pagination']['type']);
        // AI: tool name/description + inferred params.
        $this->assertSame('list_catalog', $s['tool_name']);
        $this->assertSame('q', $s['parameters'][0]['name']);
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
