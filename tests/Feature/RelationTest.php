<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Feature;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Services\ConnectorAdminService;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use RuntimeException;

/**
 * List → Detail relations (spec Obj 3): the service-layer core behind the
 * relation CRUD + the admin drill-test. Covers the validation matrix
 * (same-connector, distinct routes, list/detail typing, LLM-param targets,
 * duplicate), tenant scoping (R30) and the drill-test happy + failure paths.
 */
final class RelationTest extends TestCase
{
    use RefreshDatabase;

    private ConnectorAdminService $service;

    private ApiConnector $connector;

    private ApiRoute $list;

    private ApiRoute $detail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(TenantContext::class)->set('acme');
        $this->service = $this->app->make(ConnectorAdminService::class);

        $this->connector = $this->service->createConnector(['name' => 'C1']);
        $this->list = $this->service->createRoute($this->connector, [
            'name' => 'List users',
            'http_method' => 'GET',
            'url' => 'https://api.example.test/users',
            'mode' => 'tool',
            'endpoint_type' => 'list',
            'items_path' => 'data',
            'parameters' => [],
        ]);
        $this->detail = $this->service->createRoute($this->connector, [
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

    private function fakeEndpoints(): void
    {
        Http::fake([
            // Detail first (more specific): /users/1 → a single resource object.
            'https://api.example.test/users/*' => Http::response(
                ['id' => 1, 'name' => 'Ada', 'email' => 'ada@example.dev'],
                200,
            ),
            // List: /users → an envelope with a `data` array.
            'https://api.example.test/users' => Http::response(
                ['data' => [['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Bob']]],
                200,
            ),
        ]);
    }

    public function test_create_relation_persists_scoped_and_ordered(): void
    {
        $relation = $this->service->createRelation($this->connector, [
            'list_route_id' => $this->list->id,
            'detail_route_id' => $this->detail->id,
            'field_map' => [['from' => 'id', 'to_param' => 'id']],
            'name' => 'user drill',
        ]);

        $this->assertTrue($relation->exists);
        $this->assertSame('acme', $relation->tenant_id);
        $this->assertSame($this->list->id, $relation->list_route_id);
        $this->assertSame([['from' => 'id', 'to_param' => 'id']], $relation->field_map);
        $this->assertDatabaseHas('api_route_relations', [
            'id' => $relation->id,
            'tenant_id' => 'acme',
            'list_route_id' => $this->list->id,
            'detail_route_id' => $this->detail->id,
        ]);
    }

    public function test_relation_rejects_same_route_on_both_sides(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);
        $this->service->createRelation($this->connector, [
            'list_route_id' => $this->list->id,
            'detail_route_id' => $this->list->id,
            'field_map' => [['from' => 'id', 'to_param' => 'id']],
        ]);
    }

    public function test_relation_rejects_wrong_endpoint_types(): void
    {
        // list side is actually the DETAIL route → 422.
        try {
            $this->service->createRelation($this->connector, [
                'list_route_id' => $this->detail->id,
                'detail_route_id' => $this->list->id,
                'field_map' => [['from' => 'id', 'to_param' => 'id']],
            ]);
            $this->fail('Expected a 422 for mismatched endpoint types.');
        } catch (RuntimeException $e) {
            $this->assertSame(422, $e->getCode());
        }
    }

    public function test_relation_rejects_a_target_that_is_not_an_llm_param(): void
    {
        try {
            $this->service->createRelation($this->connector, [
                'list_route_id' => $this->list->id,
                'detail_route_id' => $this->detail->id,
                'field_map' => [['from' => 'id', 'to_param' => 'nonexistent']],
            ]);
            $this->fail('Expected a 422 for an unknown to_param.');
        } catch (RuntimeException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertStringContainsString('nonexistent', $e->getMessage());
        }
    }

    public function test_relation_rejects_a_duplicate_pair(): void
    {
        $payload = [
            'list_route_id' => $this->list->id,
            'detail_route_id' => $this->detail->id,
            'field_map' => [['from' => 'id', 'to_param' => 'id']],
        ];
        $this->service->createRelation($this->connector, $payload);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);
        $this->service->createRelation($this->connector, $payload);
    }

    public function test_find_relation_is_tenant_scoped(): void
    {
        $relation = $this->service->createRelation($this->connector, [
            'list_route_id' => $this->list->id,
            'detail_route_id' => $this->detail->id,
            'field_map' => [['from' => 'id', 'to_param' => 'id']],
        ]);

        $this->app->make(TenantContext::class)->set('globex');
        $globex = $this->app->make(ConnectorAdminService::class);

        $this->expectException(ModelNotFoundException::class);
        $globex->findRelation($relation->id);
    }

    public function test_drill_test_maps_a_list_item_into_the_detail_call(): void
    {
        $this->fakeEndpoints();
        // Populate the list route's last_test_payload with the real list body.
        $this->service->testRoute($this->list->fresh(['parameters']), []);

        $relation = $this->service->createRelation($this->connector, [
            'list_route_id' => $this->list->id,
            'detail_route_id' => $this->detail->id,
            'field_map' => [['from' => 'id', 'to_param' => 'id']],
        ]);

        $out = $this->service->drillTest($relation, null, itemIndex: 1);

        // field_map bound item[1].id (=2) → detail param `id`.
        $this->assertSame(['id' => 2], $out['arguments']);
        $this->assertTrue($out['result']->ok);
        $this->assertSame('Ada', $out['result']->body['name']);

        // dryRun must NOT persist last_test_* on the detail route.
        $this->assertNull($this->detail->fresh()->last_test_at);
    }

    public function test_drill_test_accepts_an_explicit_list_item(): void
    {
        $this->fakeEndpoints();
        $relation = $this->service->createRelation($this->connector, [
            'list_route_id' => $this->list->id,
            'detail_route_id' => $this->detail->id,
            'field_map' => [['from' => 'id', 'to_param' => 'id']],
        ]);

        $out = $this->service->drillTest($relation, ['id' => 7, 'name' => 'X'], null);
        $this->assertSame(['id' => 7], $out['arguments']);
        $this->assertTrue($out['result']->ok);
    }

    public function test_drill_test_422_when_the_mapped_field_is_absent(): void
    {
        $this->fakeEndpoints();
        $relation = $this->service->createRelation($this->connector, [
            'list_route_id' => $this->list->id,
            'detail_route_id' => $this->detail->id,
            'field_map' => [['from' => 'id', 'to_param' => 'id']],
        ]);

        // The chosen item has no `id` → mapping fails as a client-fixable 422,
        // never a silent null bound into {id}.
        try {
            $this->service->drillTest($relation, ['name' => 'no-id'], null);
            $this->fail('Expected a 422 for the missing mapped field.');
        } catch (RuntimeException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertStringContainsString('not found', $e->getMessage());
        }
    }

    public function test_deleting_a_route_removes_its_relations(): void
    {
        $relation = $this->service->createRelation($this->connector, [
            'list_route_id' => $this->list->id,
            'detail_route_id' => $this->detail->id,
            'field_map' => [['from' => 'id', 'to_param' => 'id']],
        ]);

        $this->service->deleteRoute($this->detail->fresh());

        $this->assertDatabaseMissing('api_route_relations', ['id' => $relation->id]);
    }
}
