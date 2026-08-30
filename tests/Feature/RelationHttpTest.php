<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Services\ConnectorAdminService;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;

/**
 * HTTP layer of the List → Detail relations (spec Obj 3): controller + form
 * requests + resource + routes wiring. Package default middleware is dropped
 * (the host applies its authenticated stack — R32) so a plain JSON call works.
 */
final class RelationHttpTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/admin/api-connectors';

    private ApiConnector $connector;

    private ApiRoute $list;

    private ApiRoute $detail;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('connector-api.routes.middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(TenantContext::class)->set('acme');
        $service = $this->app->make(ConnectorAdminService::class);

        $this->connector = $service->createConnector(['name' => 'C1']);
        $this->list = $service->createRoute($this->connector, [
            'name' => 'List users',
            'http_method' => 'GET',
            'url' => 'https://api.example.test/users',
            'mode' => 'tool',
            'endpoint_type' => 'list',
            'items_path' => 'data',
            'parameters' => [],
        ]);
        $this->detail = $service->createRoute($this->connector, [
            'name' => 'User detail',
            'http_method' => 'GET',
            'url' => 'https://api.example.test/users/{id}',
            'mode' => 'tool',
            'endpoint_type' => 'detail',
            'parameters' => [
                ['name' => 'id', 'location' => 'path', 'source' => 'llm', 'type' => 'integer', 'required' => true],
            ],
        ]);
    }

    public function test_create_then_list_relation_over_http(): void
    {
        $created = $this->postJson(self::PREFIX."/{$this->connector->id}/relations", [
            'list_route_id' => $this->list->id,
            'detail_route_id' => $this->detail->id,
            'field_map' => [['from' => 'id', 'to_param' => 'id']],
            'name' => 'user drill',
        ])->assertStatus(201);

        $created->assertJsonPath('data.list_route_id', $this->list->id);
        $created->assertJsonPath('data.field_map.0.to_param', 'id');
        // Compact route stubs embedded on show/list.
        $created->assertJsonPath('data.detail_route.endpoint_type', 'detail');

        $this->getJson(self::PREFIX."/{$this->connector->id}/relations")
            ->assertStatus(200)
            ->assertJsonPath('data.0.list_route.slug', $this->list->slug);
    }

    public function test_relation_validation_error_is_422_over_http(): void
    {
        // to_param is not an LLM param of the detail route → service 422.
        $this->postJson(self::PREFIX."/{$this->connector->id}/relations", [
            'list_route_id' => $this->list->id,
            'detail_route_id' => $this->detail->id,
            'field_map' => [['from' => 'id', 'to_param' => 'ghost']],
        ])->assertStatus(422);

        // Missing field_map → FormRequest 422.
        $this->postJson(self::PREFIX."/{$this->connector->id}/relations", [
            'list_route_id' => $this->list->id,
            'detail_route_id' => $this->detail->id,
        ])->assertStatus(422)->assertJsonValidationErrors('field_map');
    }

    public function test_drill_over_http_with_an_explicit_item(): void
    {
        Http::fake([
            'https://api.example.test/users/*' => Http::response(['id' => 5, 'name' => 'Zoe'], 200),
        ]);
        $relationId = $this->postJson(self::PREFIX."/{$this->connector->id}/relations", [
            'list_route_id' => $this->list->id,
            'detail_route_id' => $this->detail->id,
            'field_map' => [['from' => 'id', 'to_param' => 'id']],
        ])->json('data.id');

        $resp = $this->postJson(self::PREFIX."/relations/{$relationId}/drill", [
            'list_item' => ['id' => 5, 'name' => 'ignored'],
        ])->assertStatus(200);

        $resp->assertJsonPath('arguments.id', 5);
        $resp->assertJsonPath('result.ok', true);
        $resp->assertJsonPath('result.body.name', 'Zoe');
    }

    public function test_drill_missing_field_is_422_over_http(): void
    {
        $relationId = $this->postJson(self::PREFIX."/{$this->connector->id}/relations", [
            'list_route_id' => $this->list->id,
            'detail_route_id' => $this->detail->id,
            'field_map' => [['from' => 'id', 'to_param' => 'id']],
        ])->json('data.id');

        $this->postJson(self::PREFIX."/relations/{$relationId}/drill", [
            'list_item' => ['name' => 'no-id-here'],
        ])->assertStatus(422);
    }

    public function test_delete_relation_over_http(): void
    {
        $relationId = $this->postJson(self::PREFIX."/{$this->connector->id}/relations", [
            'list_route_id' => $this->list->id,
            'detail_route_id' => $this->detail->id,
            'field_map' => [['from' => 'id', 'to_param' => 'id']],
        ])->json('data.id');

        $this->deleteJson(self::PREFIX."/relations/{$relationId}")
            ->assertStatus(200)
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('api_route_relations', ['id' => $relationId]);
    }

    public function test_connectors_index_renders_relation_route_stubs_without_500(): void
    {
        // Regression: listConnectors eager-loads the relation routes with a
        // partial column select; the stub reads endpoint_type, so the select must
        // carry it or the resource dereferences a null enum (was a 500).
        $this->postJson(self::PREFIX."/{$this->connector->id}/relations", [
            'list_route_id' => $this->list->id,
            'detail_route_id' => $this->detail->id,
            'field_map' => [['from' => 'id', 'to_param' => 'id']],
        ])->assertStatus(201);

        $this->getJson(self::PREFIX)
            ->assertStatus(200)
            ->assertJsonPath('data.0.relations.0.list_route.name', 'List users')
            ->assertJsonPath('data.0.relations.0.list_route.endpoint_type', 'list')
            ->assertJsonPath('data.0.relations.0.detail_route.endpoint_type', 'detail');
    }
}
