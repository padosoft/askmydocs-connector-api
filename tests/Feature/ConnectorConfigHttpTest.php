<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Services\ConnectorAdminService;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;

/**
 * HTTP surface for the canonical config JSON: create/update accept a `{config}`
 * envelope, the resource emits a `config` block, and the two config-body
 * endpoints (test-config / produce-config) dry-run + AI-fill an UNSAVED config.
 */
final class ConnectorConfigHttpTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/admin/api-connectors';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('connector-api.routes.middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(TenantContext::class)->set('acme');
    }

    private function connector(): ApiConnector
    {
        return $this->app->make(ConnectorAdminService::class)->createConnector(['name' => 'C1']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function config(array $overrides = []): array
    {
        return array_replace_recursive([
            'identity' => ['name' => 'Users', 'slug' => null, 'description' => null, 'mode' => 'tool'],
            'request' => ['http_method' => 'GET', 'url' => 'https://api.example.com/users', 'auth_profile_id' => null, 'params' => []],
            'response' => ['endpoint_type' => 'auto', 'items_path' => null, 'transform' => null, 'pagination' => null],
            'options' => ['timeout_ms' => null, 'cache_ttl_s' => null, 'rate_limit' => null],
        ], $overrides);
    }

    private function fakeList(): void
    {
        Http::fake(['api.example.com/*' => Http::response([
            'data' => [['id' => 1, 'name' => 'x']],
            'meta' => ['next_cursor' => 'c2'],
        ], 200)]);
    }

    public function test_create_route_accepts_a_config_envelope_and_returns_the_config_block(): void
    {
        $connector = $this->connector();

        $response = $this->postJson(self::PREFIX."/{$connector->id}/routes", [
            'config' => $this->config([
                'request' => ['params' => [
                    ['name' => 'q', 'location' => 'query', 'source' => 'llm', 'type' => 'string', 'required' => false, 'sort_order' => 0],
                ]],
            ]),
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Users');                       // flat kept
        $response->assertJsonPath('data.config.identity.name', 'Users');       // config block emitted
        $response->assertJsonPath('data.config.request.params.0.name', 'q');
        $this->assertDatabaseHas('api_routes', ['name' => 'Users', 'tenant_id' => 'acme']);
        $this->assertDatabaseHas('api_route_parameters', ['name' => 'q', 'tenant_id' => 'acme']);
    }

    public function test_update_route_accepts_a_config_envelope_as_a_full_replace(): void
    {
        $connector = $this->connector();
        $created = $this->postJson(self::PREFIX."/{$connector->id}/routes", ['config' => $this->config()])->json('data');

        $response = $this->patchJson(self::PREFIX."/routes/{$created['id']}", [
            'config' => $this->config(['identity' => ['description' => 'Now described.']]),
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.config.identity.description', 'Now described.');
        $this->assertDatabaseHas('api_routes', ['id' => $created['id'], 'description' => 'Now described.']);
    }

    public function test_a_config_missing_the_url_is_a_422_keyed_under_config(): void
    {
        $connector = $this->connector();
        $config = $this->config();
        unset($config['request']['url']);

        $this->postJson(self::PREFIX."/{$connector->id}/routes", ['config' => $config])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['config.request.url']);
    }

    public function test_test_config_dry_runs_an_unsaved_config(): void
    {
        $this->fakeList();
        $connector = $this->connector();

        $response = $this->postJson(self::PREFIX."/{$connector->id}/routes/test-config", [
            'config' => $this->config(),
            'example_args' => [],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('test.ok', true);
        $response->assertJsonPath('endpoint_type', 'list');
        $response->assertJsonPath('items_path', 'data');
        $response->assertJsonPath('detected_pagination.type', 'cursor');
    }

    public function test_produce_config_returns_a_filled_config_and_final_test(): void
    {
        $this->fakeList();
        $connector = $this->connector();

        $response = $this->postJson(self::PREFIX."/{$connector->id}/routes/produce-config", [
            'config' => $this->config(),
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('source', 'response');
        $response->assertJsonPath('final_test.ok', true);
        // Deterministic (Null analyst in standalone): the structural fields are filled.
        $response->assertJsonPath('config.response.endpoint_type', 'list');
        $response->assertJsonPath('config.response.pagination.type', 'cursor');
    }
}
