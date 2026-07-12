<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Services\ConnectorAdminService;
use Padosoft\AskMyDocsConnectorApi\Support\EndpointType;
use Padosoft\AskMyDocsConnectorApi\Support\RouteConfig;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;

/**
 * The {@see RouteConfig} codec is the ONLY bridge between the canonical config
 * JSON (the UI/AI/API pivot) and the normalized columns the runtime reads. Its
 * round-trip must be lossless over the operator surface, or a "save the config
 * you were shown" becomes a silent data mutation.
 */
final class RouteConfigTest extends TestCase
{
    use RefreshDatabase;

    private ConnectorAdminService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(TenantContext::class)->set('acme');
        $this->service = $this->app->make(ConnectorAdminService::class);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function richRoute(array $overrides = []): ApiRoute
    {
        $connector = $this->service->createConnector(['name' => 'C1']);

        return $this->service->createRoute($connector, array_merge([
            'name' => 'List users',
            'description' => 'Returns users; drillable by id.',
            'http_method' => 'GET',
            'url' => 'https://api.example.com/users/{id}',
            'mode' => 'tool',
            'endpoint_type' => 'list',           // locked override
            'items_path' => 'data',
            'output_transform' => ['include' => ['data.*.id', 'data.*.name'], 'exclude' => []],
            'pagination' => ['type' => 'cursor', 'cursor_param' => 'cursor', 'next_cursor_path' => 'meta.next_cursor'],
            'timeout_ms' => 8000,
            'cache_ttl_s' => 30,
            'rate_limit' => 60,
            'parameters' => [
                ['name' => 'q', 'location' => 'query', 'source' => 'llm', 'type' => 'string', 'required' => false, 'description' => 'search', 'sort_order' => 0],
                ['name' => 'id', 'location' => 'path', 'source' => 'llm', 'type' => 'integer', 'required' => true, 'sort_order' => 1],
                ['name' => 'fmt', 'location' => 'query', 'source' => 'fixed', 'type' => 'string', 'value' => 'json', 'sort_order' => 2],
                ['name' => 'X-Key', 'location' => 'header', 'source' => 'secret', 'type' => 'string', 'secret_ref' => 'api_key', 'sort_order' => 3],
            ],
        ], $overrides));
    }

    public function test_from_route_produces_the_grouped_canonical_shape(): void
    {
        $config = RouteConfig::fromRoute($this->richRoute()->loadMissing('parameters'));

        $this->assertSame(['identity', 'request', 'response', 'options'], array_keys($config));
        $this->assertSame('List users', $config['identity']['name']);
        $this->assertSame('tool', $config['identity']['mode']);
        $this->assertSame('GET', $config['request']['http_method']);
        $this->assertSame('https://api.example.com/users/{id}', $config['request']['url']);

        // Locked list → the explicit type is surfaced (not 'auto').
        $this->assertSame('list', $config['response']['endpoint_type']);
        $this->assertSame('data', $config['response']['items_path']);
        $this->assertSame(['type' => 'cursor', 'cursor_param' => 'cursor', 'next_cursor_path' => 'meta.next_cursor'], $config['response']['pagination']);
        $this->assertSame(8000, $config['options']['timeout_ms']);

        $params = $config['request']['params'];
        $this->assertCount(4, $params);
        // Fixed carries value, NOT secret_ref; secret carries secret_ref, NOT value; llm carries neither.
        $this->assertArrayNotHasKey('value', $params[0]);        // llm
        $this->assertArrayNotHasKey('secret_ref', $params[0]);
        $this->assertSame('json', $params[2]['value']);          // fixed
        $this->assertArrayNotHasKey('secret_ref', $params[2]);
        $this->assertSame('api_key', $params[3]['secret_ref']);  // secret
        $this->assertArrayNotHasKey('value', $params[3]);
    }

    public function test_config_round_trips_losslessly_through_update(): void
    {
        $route = $this->richRoute();
        $before = RouteConfig::fromRoute($route->loadMissing('parameters'));

        // Apply the config we just read straight back — a no-op "save".
        $after = $this->service->updateRoute($route, RouteConfig::applyToRoute($before));

        // Byte-for-byte identical config (the operator projection is preserved).
        $this->assertSame($before, RouteConfig::fromRoute($after->loadMissing('parameters')));
        // …and the raw taxonomy columns + param_mapping are unchanged.
        $this->assertSame(EndpointType::List, $after->endpoint_type);
        $this->assertTrue($after->endpoint_type_locked);
        $this->assertCount(4, $after->parameters);
        $this->assertSame($route->param_mapping, $after->param_mapping);
    }

    public function test_auto_unlocked_endpoint_type_round_trips_without_clobbering_the_column(): void
    {
        // A detector-set type on an UNLOCKED route: column=list but not locked.
        $route = $this->richRoute(['endpoint_type' => 'auto']);
        $route->endpoint_type = EndpointType::List;
        $route->save();
        $route->loadMissing('parameters');

        $config = RouteConfig::fromRoute($route);
        // Unlocked ⇒ the config reads 'auto', hiding the detector's guess.
        $this->assertSame('auto', $config['response']['endpoint_type']);

        $after = $this->service->updateRoute($route, RouteConfig::applyToRoute($config));

        // Applying 'auto' leaves the column untouched (still list) + unlocked,
        // so fromRoute keeps reporting 'auto' — stable across the round-trip.
        $this->assertSame(EndpointType::List, $after->endpoint_type);
        $this->assertFalse($after->endpoint_type_locked);
        $this->assertSame('auto', RouteConfig::fromRoute($after->loadMissing('parameters'))['response']['endpoint_type']);
    }

    public function test_detail_locked_and_empty_params_round_trip(): void
    {
        $route = $this->richRoute([
            'endpoint_type' => 'detail',
            'items_path' => null,
            'parameters' => [],
            'pagination' => null,
        ]);
        $before = RouteConfig::fromRoute($route->loadMissing('parameters'));

        $this->assertSame('detail', $before['response']['endpoint_type']);
        $this->assertSame([], $before['request']['params']);
        $this->assertNull($before['response']['pagination']);

        $after = $this->service->updateRoute($route, RouteConfig::applyToRoute($before));

        $this->assertSame($before, RouteConfig::fromRoute($after->loadMissing('parameters')));
        $this->assertSame(EndpointType::Detail, $after->endpoint_type);
        $this->assertTrue($after->endpoint_type_locked);
        $this->assertCount(0, $after->parameters);
    }

    public function test_secret_params_never_carry_a_credential_value(): void
    {
        $config = RouteConfig::fromRoute($this->richRoute()->loadMissing('parameters'));
        $secret = $config['request']['params'][3];

        $this->assertSame('secret', $secret['source']);
        $this->assertSame('api_key', $secret['secret_ref']); // a KEY name, not a secret
        $this->assertArrayNotHasKey('value', $secret);
        // Nothing anywhere in the serialized config looks like a credential value.
        $this->assertStringNotContainsString('secret-value', json_encode($config) ?: '');
    }
}
