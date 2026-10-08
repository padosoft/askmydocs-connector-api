<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;

/**
 * Light HTTP CRUD happy-path against the admin routes. The package default
 * middleware is `['api']` (unauthenticated) — the host overrides it with its
 * authenticated stack (R32) — so a plain JSON POST works in standalone tests.
 *
 * The store handler auto-fills tenant_id from the active {@see TenantContext}
 * (R31): we set it to a non-default tenant and assert the row lands scoped to it.
 */
final class ConnectorCrudTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/admin/api-connectors';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Standalone-dev: no auth stack. Drop middleware so the test does not
        // depend on a host-registered `api` rate limiter (R32 says the host
        // overrides this anyway); the routes use plain int params (no binding).
        $app['config']->set('connector-api.routes.middleware', []);
    }

    public function test_create_connector_returns_201_and_persists_scoped_to_the_active_tenant(): void
    {
        $this->app->make(TenantContext::class)->set('acme');

        $response = $this->postJson(self::PREFIX, [
            'name' => 'Weather API',
            'description' => 'Live weather lookups',
            'base_url' => 'https://api.weather.example',
            'is_active' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Weather API');
        $response->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('api_connectors', [
            'name' => 'Weather API',
            'tenant_id' => 'acme',
        ]);
    }

    public function test_validation_error_returns_422(): void
    {
        // `name` is required.
        $this->postJson(self::PREFIX, ['description' => 'no name'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_index_lists_only_current_tenant_connectors(): void
    {
        $tenants = $this->app->make(TenantContext::class);

        $tenants->set('acme');
        $this->postJson(self::PREFIX, ['name' => 'Acme Conn'])->assertStatus(201);

        $tenants->set('globex');
        $this->postJson(self::PREFIX, ['name' => 'Globex Conn'])->assertStatus(201);

        // Listing as globex must see only globex's connector.
        $response = $this->getJson(self::PREFIX);
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Globex Conn');
    }

    public function test_index_exposes_saved_auth_profiles_without_credentials(): void
    {
        $this->app->make(TenantContext::class)->set('acme');
        $connectorId = $this->postJson(self::PREFIX, ['name' => 'C1'])
            ->assertCreated()->json('data.id');

        $profileId = $this->postJson(self::PREFIX."/{$connectorId}/auth-profiles", [
            'type' => 'basic',
            'credentials' => ['username' => 'test-user', 'password' => 'test-password'],
        ])->assertCreated()->json('data.id');

        $response = $this->getJson(self::PREFIX)->assertOk();
        $response->assertJsonStructure(['data' => [['auth_profiles']]]);
        $response->assertJsonCount(1, 'data.0.auth_profiles');
        $response->assertJsonPath('data.0.auth_profiles.0.id', $profileId);
        $response->assertJsonPath('data.0.auth_profiles.0.type', 'basic');
        $response->assertJsonPath('data.0.auth_profiles.0.has_credentials', true);
        $response->assertJsonMissingPath('data.0.auth_profiles.0.credentials');
        $this->assertStringNotContainsString('test-password', $response->getContent());
    }

    public function test_show_and_delete_roundtrip(): void
    {
        $this->app->make(TenantContext::class)->set('acme');

        $created = $this->postJson(self::PREFIX, ['name' => 'Temp Conn'])->assertStatus(201)->json('data.id');

        $this->getJson(self::PREFIX.'/'.$created)
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Temp Conn');

        $this->deleteJson(self::PREFIX.'/'.$created)
            ->assertStatus(200)
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('api_connectors', ['id' => $created]);
    }

    public function test_create_route_accepts_an_endpoint_type_override_and_exposes_it(): void
    {
        $this->app->make(TenantContext::class)->set('acme');
        $connectorId = $this->postJson(self::PREFIX, ['name' => 'C1'])->assertStatus(201)->json('data.id');

        $created = $this->postJson(self::PREFIX."/{$connectorId}/routes", [
            'name' => 'User detail',
            'http_method' => 'GET',
            'url' => 'https://api.example.test/users/{id}',
            'mode' => 'tool',
            'endpoint_type' => 'detail',
        ])->assertStatus(201);

        // Resource carries the taxonomy; the explicit choice is locked.
        $created->assertJsonPath('data.endpoint_type', 'detail');
        $created->assertJsonPath('data.endpoint_type_locked', true);
        $this->assertDatabaseHas('api_routes', [
            'id' => $created->json('data.id'),
            'endpoint_type' => 'detail',
            'endpoint_type_locked' => true,
        ]);
    }

    public function test_create_route_rejects_an_invalid_endpoint_type(): void
    {
        $this->app->make(TenantContext::class)->set('acme');
        $connectorId = $this->postJson(self::PREFIX, ['name' => 'C1'])->assertStatus(201)->json('data.id');

        // 'unknown' is not operator-settable; only auto|list|detail are accepted.
        $this->postJson(self::PREFIX."/{$connectorId}/routes", [
            'name' => 'Bad',
            'http_method' => 'GET',
            'url' => 'https://api.example.test/x',
            'mode' => 'tool',
            'endpoint_type' => 'unknown',
        ])->assertStatus(422)->assertJsonValidationErrors('endpoint_type');
    }
}
