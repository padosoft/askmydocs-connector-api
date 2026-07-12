<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorApi\Contracts\ResponseAnalyst;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Services\ConnectorAdminService;
use Padosoft\AskMyDocsConnectorApi\Support\UrlGuard;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;

/**
 * The canonical config JSON is the pivot: {@see ConnectorAdminService::testConfig()}
 * dry-runs an UNSAVED config (the modal's "Testa" works in create mode), and
 * {@see ConnectorAdminService::produceConfig()} is the single AI pass that fills
 * it — deterministic classification/detection WINNING over the model.
 */
final class ConnectorConfigProduceTest extends TestCase
{
    use RefreshDatabase;

    private ConnectorAdminService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(TenantContext::class)->set('acme');
        $this->service = $this->app->make(ConnectorAdminService::class);
    }

    private function connector(): ApiConnector
    {
        return $this->service->createConnector(['name' => 'C1']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function inputConfig(array $overrides = []): array
    {
        return array_replace_recursive([
            'identity' => ['name' => 'Catalog', 'slug' => null, 'description' => null, 'mode' => 'tool'],
            'request' => ['http_method' => 'GET', 'url' => 'https://api.example.com/catalog', 'auth_profile_id' => null, 'params' => []],
            'response' => ['endpoint_type' => 'auto', 'items_path' => null, 'transform' => null, 'pagination' => null],
            'options' => ['timeout_ms' => null, 'cache_ttl_s' => null, 'rate_limit' => null],
        ], $overrides);
    }

    private function fakeList(): void
    {
        Http::fake([
            'api.example.com/*' => Http::response([
                'data' => [['id' => 1, 'name' => 'x'], ['id' => 2, 'name' => 'y']],
                'meta' => ['next_cursor' => 'c2'],
            ], 200),
        ]);
    }

    /** A stub analyst whose produceConfig returns a fixed grouped config. */
    private function stubAnalyst(?array $config): void
    {
        $this->app->bind(ResponseAnalyst::class, fn (): ResponseAnalyst => new class($config) implements ResponseAnalyst
        {
            /** @param array<string,mixed>|null $config */
            public function __construct(private ?array $config) {}

            public function produceConfig(array $context): ?array
            {
                return $this->config;
            }
        });
        $this->service = $this->app->make(ConnectorAdminService::class);
    }

    public function test_test_config_dry_runs_an_unsaved_config_and_classifies_it(): void
    {
        $this->fakeList();

        $out = $this->service->testConfig($this->connector(), $this->inputConfig());

        $this->assertTrue($out['test']->ok);
        $this->assertSame('list', $out['endpoint_type']);
        $this->assertSame('data', $out['items_path']);
        $this->assertSame('cursor', $out['detected_pagination']['type']);
        $this->assertSame(2, $out['item_count']);
    }

    public function test_produce_config_lets_deterministic_classification_win_over_the_ai(): void
    {
        $this->fakeList();
        // AI mislabels it a 'detail' and omits pagination — the classifier/detector must win.
        $this->stubAnalyst([
            'identity' => ['name' => 'list_users', 'slug' => null, 'description' => 'Users.', 'mode' => 'tool'],
            'request' => ['http_method' => 'GET', 'url' => 'https://api.example.com/catalog', 'auth_profile_id' => null,
                'params' => [['name' => 'q', 'location' => 'query', 'source' => 'llm', 'type' => 'string', 'required' => true, 'sort_order' => 0]]],
            'response' => ['endpoint_type' => 'detail', 'items_path' => null, 'transform' => null, 'pagination' => null],
            'options' => ['timeout_ms' => null, 'cache_ttl_s' => null, 'rate_limit' => null],
        ]);

        $out = $this->service->produceConfig($this->connector(), $this->inputConfig(), []);

        $this->assertSame('response', $out['source']);
        $this->assertTrue($out['final_test']->ok);
        $config = $out['config'];
        $this->assertSame('list_users', $config['identity']['name']);         // AI identity kept
        $this->assertSame('list', $config['response']['endpoint_type']);      // classifier won
        $this->assertSame('data', $config['response']['items_path']);
        $this->assertSame('cursor', $config['response']['pagination']['type']); // detector won
        $this->assertSame('q', $config['request']['params'][0]['name']);
    }

    public function test_produce_config_degrades_to_deterministic_only_without_ai(): void
    {
        $this->fakeList(); // default Null analyst → no AI

        $out = $this->service->produceConfig($this->connector(), $this->inputConfig(), []);

        $this->assertSame('response', $out['source']);
        $config = $out['config'];
        // The structural fields are still filled deterministically…
        $this->assertSame('list', $config['response']['endpoint_type']);
        $this->assertSame('data', $config['response']['items_path']);
        $this->assertSame('cursor', $config['response']['pagination']['type']);
        // …and the identity falls back to the input.
        $this->assertSame('Catalog', $config['identity']['name']);
    }

    public function test_produce_config_returns_null_config_on_a_non_json_response(): void
    {
        Http::fake(['api.example.com/*' => Http::response('<html>nope</html>', 200)]);

        $out = $this->service->produceConfig($this->connector(), $this->inputConfig(), []);

        $this->assertNull($out['config']);
        $this->assertSame('none', $out['source']);
        $this->assertFalse($out['final_test']->isJson);
    }

    public function test_produce_config_reads_from_openapi_when_a_spec_url_is_given(): void
    {
        Http::fake([
            'api.docs.example/*' => Http::response([
                'openapi' => '3.0.0',
                'servers' => [['url' => 'https://api.example.com']],
                'paths' => ['/catalog' => ['get' => [
                    'operationId' => 'listCatalog',
                    'summary' => 'List the catalog.',
                    'parameters' => [['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string']]],
                    'responses' => ['200' => ['content' => ['application/json' => ['schema' => [
                        'type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => ['type' => 'object']]],
                    ]]]]],
                ]]],
            ], 200),
            'api.example.com/*' => Http::response(['data' => [['id' => 1]]], 200),
        ]);
        $this->app->instance(UrlGuard::class, new UrlGuard(enabled: false));
        $this->service = $this->app->make(ConnectorAdminService::class);

        $out = $this->service->produceConfig($this->connector(), $this->inputConfig(), [], 'https://api.docs.example/openapi.json');

        $this->assertSame('openapi', $out['source']);
        $config = $out['config'];
        $this->assertSame('listCatalog', $config['identity']['name']); // operationId (normalised at save)
        $this->assertSame('List the catalog.', $config['identity']['description']);
        $this->assertSame('q', $config['request']['params'][0]['name']);
        $this->assertSame('list', $config['response']['endpoint_type']);
    }
}
