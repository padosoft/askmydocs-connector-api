<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Support\HttpMethod;
use Padosoft\AskMyDocsConnectorApi\Support\OpenApiImporter;
use Padosoft\AskMyDocsConnectorApi\Support\UrlGuard;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;
use RuntimeException;

/**
 * {@see OpenApiImporter} reads an OpenAPI spec URL and extracts the config for
 * the SINGLE operation matching a route (method + path over the server base),
 * resolving `$ref`s. Deterministic — no live call, no LLM, works when the
 * endpoint needs auth.
 */
final class OpenApiImporterTest extends TestCase
{
    private const SPEC = [
        'openapi' => '3.0.0',
        'servers' => [['url' => 'https://api.shop.example/v1']],
        'paths' => [
            '/products' => [
                'get' => [
                    'operationId' => 'listProducts',
                    'summary' => 'List products',
                    'parameters' => [
                        ['name' => 'q', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'], 'description' => 'Search term'],
                        ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']],
                        ['$ref' => '#/components/parameters/PerPage'],
                        ['name' => 'Authorization', 'in' => 'header', 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => ['content' => ['application/json' => ['schema' => [
                            'type' => 'object',
                            'properties' => ['data' => ['type' => 'array', 'items' => ['type' => 'object']]],
                        ]]]],
                    ],
                ],
            ],
            '/products/{id}' => [
                'get' => [
                    'operationId' => 'getProduct',
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                    'responses' => ['200' => ['content' => ['application/json' => ['schema' => [
                        'type' => 'object', 'properties' => ['id' => ['type' => 'integer']],
                    ]]]]],
                ],
            ],
        ],
        'components' => [
            'parameters' => ['PerPage' => ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer']]],
        ],
    ];

    private function importer(): OpenApiImporter
    {
        return new OpenApiImporter(new UrlGuard(enabled: false));
    }

    private function route(string $url, HttpMethod $method = HttpMethod::GET): ApiRoute
    {
        $route = new ApiRoute;
        $route->url = $url;
        $route->http_method = $method;

        return $route;
    }

    private function fakeSpec(): void
    {
        Http::fake(['api.docs.example/*' => Http::response(self::SPEC, 200)]);
    }

    public function test_it_extracts_the_list_operation_with_params_pagination_and_type(): void
    {
        $this->fakeSpec();

        $config = $this->importer()->configForRoute(
            'https://api.docs.example/openapi.json',
            $this->route('https://api.shop.example/v1/products'),
        );

        $this->assertSame('listProducts', $config['tool_name']);
        $this->assertSame('List products', $config['tool_description']);
        $this->assertSame('list', $config['endpoint_type']);
        $this->assertSame('data', $config['items_path']);

        $byName = collect($config['parameters'])->keyBy('name');
        $this->assertSame('query', $byName['q']['location']);
        $this->assertSame('llm', $byName['q']['source']);
        $this->assertSame('Search term', $byName['q']['description']);
        $this->assertSame('integer', $byName['page']['type']);
        // Resolved via $ref.
        $this->assertSame('per_page', $byName['per_page']['name']);
        // Auth header inferred as a secret.
        $this->assertSame('secret', $byName['Authorization']['source']);

        // Pagination inferred from the param names.
        $this->assertSame('page', $config['pagination']['type']);
        $this->assertSame('page', $config['pagination']['page_param']);
        $this->assertSame('per_page', $config['pagination']['size_param']);
    }

    public function test_it_matches_a_templated_detail_path(): void
    {
        $this->fakeSpec();

        $config = $this->importer()->configForRoute(
            'https://api.docs.example/openapi.json',
            $this->route('https://api.shop.example/v1/products/123'),
        );

        $this->assertSame('getProduct', $config['tool_name']);
        $this->assertSame('detail', $config['endpoint_type']);
        $this->assertTrue($config['parameters'][0]['required']);      // path param
        $this->assertSame('path', $config['parameters'][0]['location']);
        $this->assertArrayNotHasKey('pagination', $config);
    }

    public function test_it_returns_null_when_the_route_is_not_in_the_spec(): void
    {
        $this->fakeSpec();

        $config = $this->importer()->configForRoute(
            'https://api.docs.example/openapi.json',
            $this->route('https://api.shop.example/v1/orders'),
        );

        $this->assertNull($config);
    }

    public function test_it_throws_on_a_non_openapi_body(): void
    {
        Http::fake(['api.docs.example/*' => Http::response('<html>not json</html>', 200)]);

        $this->expectException(RuntimeException::class);
        $this->importer()->configForRoute(
            'https://api.docs.example/spec',
            $this->route('https://api.shop.example/v1/products'),
        );
    }
}
