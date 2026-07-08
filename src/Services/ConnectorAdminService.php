<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorApi\Models\ApiAuthProfile;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteParameter;
use Padosoft\AskMyDocsConnectorApi\Support\HttpMethod;
use Padosoft\AskMyDocsConnectorApi\Support\ParamSource;
use Padosoft\AskMyDocsConnectorApi\Support\ParamType;
use Padosoft\AskMyDocsConnectorApi\Support\RouteStatus;
use Padosoft\AskMyDocsConnectorApi\Support\TestResult;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use RuntimeException;

/**
 * Orchestrates the admin write-side of the API connector (spec §5). Controllers
 * stay thin (request → service → resource); persistence, the param_mapping
 * build, the test → schema → tool-definition pipeline and tenant scoping (R30)
 * all live here.
 *
 * Every loader is tenant-scoped: ids guessed from another tenant 404 (R30 — we
 * deliberately do NOT use implicit route-model binding).
 */
final class ConnectorAdminService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ApiRouteTester $tester,
        private readonly SchemaInferrer $schemaInferrer,
        private readonly ToolDefinitionGenerator $toolGenerator,
        private readonly ApiToolExecutor $executor,
    ) {}

    /* ----------------------------------------------------------------------
     | Tenant-scoped loaders (R30) — never rely on implicit route binding.
     * -------------------------------------------------------------------- */

    public function findConnector(int $id): ApiConnector
    {
        return ApiConnector::forTenant($this->currentTenant())
            ->findOrFail($id);
    }

    public function findAuthProfile(int $id): ApiAuthProfile
    {
        return ApiAuthProfile::forTenant($this->currentTenant())
            ->findOrFail($id);
    }

    public function findRoute(int $id): ApiRoute
    {
        return ApiRoute::forTenant($this->currentTenant())
            ->findOrFail($id);
    }

    /* ----------------------------------------------------------------------
     | Connectors
     * -------------------------------------------------------------------- */

    /**
     * @return Collection<int,ApiConnector>
     */
    public function listConnectors(): Collection
    {
        $query = ApiConnector::forTenant($this->currentTenant())->with('routes');
        $query->orderBy('name');

        return $query->get();
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function createConnector(array $data): ApiConnector
    {
        $connector = new ApiConnector;
        $connector->fill($this->connectorAttributes($data));
        $this->persist($connector);

        return $connector->fresh(['routes', 'authProfiles']) ?? $connector;
    }

    /**
     * R28: rejects a `project_key` change while the connector owns routes,
     * because the routes' slug uniqueness is `(tenant_id, project_key, slug)` —
     * moving the project would silently orphan / desync that constraint.
     *
     * @param  array<string,mixed>  $data
     *
     * @throws RuntimeException with code 422 when the R28 guard fires.
     */
    public function updateConnector(ApiConnector $connector, array $data): ApiConnector
    {
        if (array_key_exists('project_key', $data)) {
            $this->assertProjectKeyChangeAllowed($connector, $data['project_key']);
        }

        $connector->fill($this->connectorAttributes($data, partial: true));
        $this->persist($connector);

        return $connector->fresh(['routes', 'authProfiles']) ?? $connector;
    }

    public function deleteConnector(ApiConnector $connector): void
    {
        // FK cascade removes auth profiles + routes (+ parameters) on delete.
        if (! $connector->delete()) {
            throw new RuntimeException('Failed to delete connector.');
        }
    }

    /* ----------------------------------------------------------------------
     | Auth profiles
     * -------------------------------------------------------------------- */

    /**
     * @param  array<string,mixed>  $data
     */
    public function createAuthProfile(ApiConnector $connector, array $data): ApiAuthProfile
    {
        $profile = new ApiAuthProfile;
        $profile->api_connector_id = $connector->id;
        $profile->tenant_id = $connector->tenant_id;
        $profile->type = $data['type'];
        $profile->credentials = $this->arrayOrNull($data['credentials'] ?? null);
        $profile->config = $this->arrayOrNull($data['config'] ?? null);
        $this->persist($profile);

        return $profile;
    }

    /**
     * Credentials are merged only when the request supplies them, so a blank
     * edit form never wipes the stored secret; they are never echoed back.
     *
     * @param  array<string,mixed>  $data
     */
    public function updateAuthProfile(ApiAuthProfile $profile, array $data): ApiAuthProfile
    {
        if (array_key_exists('type', $data)) {
            $profile->type = $data['type'];
        }

        if (array_key_exists('config', $data)) {
            $profile->config = $this->arrayOrNull($data['config']);
        }

        if (array_key_exists('credentials', $data)) {
            $incoming = $this->arrayOrNull($data['credentials']);
            $existing = is_array($profile->credentials) ? $profile->credentials : [];
            $profile->credentials = $incoming === null
                ? $existing
                : array_merge($existing, $incoming);
        }

        $this->persist($profile);

        return $profile;
    }

    public function deleteAuthProfile(ApiAuthProfile $profile): void
    {
        if (! $profile->delete()) {
            throw new RuntimeException('Failed to delete auth profile.');
        }
    }

    /* ----------------------------------------------------------------------
     | Routes
     * -------------------------------------------------------------------- */

    /**
     * @param  array<string,mixed>  $data
     */
    public function createRoute(ApiConnector $connector, array $data): ApiRoute
    {
        return DB::transaction(function () use ($connector, $data): ApiRoute {
            $route = new ApiRoute;
            $route->api_connector_id = $connector->id;
            $route->tenant_id = $connector->tenant_id;
            $route->project_key = $connector->projectScope();
            $route->status = RouteStatus::Draft;
            $this->fillRouteAttributes($route, $data, $connector);
            $this->persist($route);

            $parameters = $this->paramList($data);
            $this->syncParameters($route, $parameters);
            $route->param_mapping = $this->buildParamMapping($parameters);
            $this->persist($route);

            return $this->loadRoute($route);
        });
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function updateRoute(ApiRoute $route, array $data): ApiRoute
    {
        return DB::transaction(function () use ($route, $data): ApiRoute {
            $connector = $this->connectorOf($route);
            $this->fillRouteAttributes($route, $data, $connector);
            // Re-sync project_key from the connector (source of truth, R28).
            $route->project_key = $connector->projectScope();

            if (array_key_exists('parameters', $data)) {
                $parameters = $this->paramList($data);
                $this->syncParameters($route, $parameters, replace: true);
                $route->param_mapping = $this->buildParamMapping($parameters);
            }

            $this->persist($route);

            return $this->loadRoute($route);
        });
    }

    public function deleteRoute(ApiRoute $route): void
    {
        if (! $route->delete()) {
            throw new RuntimeException('Failed to delete route.');
        }
    }

    /**
     * Ad-hoc "playground" probe — fire a raw, unauthenticated, NON-persisted live
     * call and return the classified {@see TestResult}. No connector/route rows
     * are written and no schema/tool is inferred: a read-only diagnostic behind
     * `can:manageConnectors`. Tenant scoping (R30) is not applicable (nothing is
     * stored); the surface stays admin-gated at the route/middleware level.
     *
     * @param  array<string,string>  $headers
     * @param  array<string,mixed>  $query
     * @param  array<string,mixed>|null  $body
     */
    public function probe(
        HttpMethod $method,
        string $url,
        array $headers = [],
        array $query = [],
        ?array $body = null,
    ): TestResult {
        return $this->tester->probe($method, $url, $headers, $query, $body);
    }

    /**
     * Run the real test call and, on a JSON success, derive + persist the input
     * schema, output schema and tool definition, promoting the route to
     * `tested`. The slug is taken from the generated tool name ONLY when the
     * operator has not set one (keeps an operator override intact).
     *
     * @param  array<string,mixed>  $exampleArgs
     * @return array{result: TestResult, route: ApiRoute}
     */
    public function testRoute(ApiRoute $route, array $exampleArgs): array
    {
        $route->loadMissing('parameters');
        $result = $this->tester->test($route, $exampleArgs);

        if (! $result->ok || ! $result->isJson) {
            // Still a valid outcome to display (R14): the route keeps whatever
            // last_test_* the tester persisted; we do not fabricate schemas.
            return ['result' => $result, 'route' => $this->loadRoute($route)];
        }

        $inputSchema = $this->schemaInferrer->inferInput($route->parameters);
        $outputSchema = $this->schemaInferrer->inferOutput($result->body);
        $definition = $this->toolGenerator->generate($route, $inputSchema, $result->body);

        $slugUnset = $route->slug === '' || $route->slug === $this->toolGenerator->normalizeSlug($route->name);

        $route->input_schema = $inputSchema;
        $route->output_schema = $outputSchema;
        $route->tool_definition = $definition;
        if ($slugUnset) {
            $route->slug = $definition['name'];
        }
        $route->status = RouteStatus::Tested;
        $this->persist($route);

        return ['result' => $result, 'route' => $this->loadRoute($route)];
    }

    /**
     * Re-run the tool-definition generator against the already-inferred input
     * schema (falling back to the live params) + the last test payload.
     *
     * @return array<string,mixed> the regenerated tool_definition
     *
     * @throws RuntimeException with code 422 when the route was never tested.
     */
    public function regenerateDescription(ApiRoute $route): array
    {
        $route->loadMissing('parameters');

        $inputSchema = is_array($route->input_schema) ? $route->input_schema : null;
        if ($inputSchema === null) {
            throw new RuntimeException('Test the route before generating its description.', 422);
        }

        $definition = $this->toolGenerator->generate($route, $inputSchema, $route->last_test_payload);
        $route->tool_definition = $definition;
        $this->persist($route);

        return $definition;
    }

    /**
     * @throws RuntimeException with code 422 when the route is not yet tested.
     */
    public function activateRoute(ApiRoute $route): ApiRoute
    {
        if ($route->status !== RouteStatus::Tested) {
            throw new RuntimeException('Test the route before activating.', 422);
        }

        $route->status = RouteStatus::Active;
        $this->persist($route);

        return $this->loadRoute($route);
    }

    public function disableRoute(ApiRoute $route): ApiRoute
    {
        $route->status = RouteStatus::Disabled;
        $this->persist($route);

        return $this->loadRoute($route);
    }

    /**
     * "Prova tool" — execute the route with operator-supplied arguments and no
     * conversation context.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed> the executor's tool_result
     */
    public function tryRoute(ApiRoute $route, array $arguments): array
    {
        $route->loadMissing('parameters');

        return $this->executor->execute($route, $arguments, []);
    }

    /* ----------------------------------------------------------------------
     | Internals
     * -------------------------------------------------------------------- */

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function connectorAttributes(array $data, bool $partial = false): array
    {
        $keys = ['name', 'description', 'project_key', 'base_url', 'headers', 'is_active'];
        $attributes = [];
        foreach ($keys as $key) {
            if ($partial && ! array_key_exists($key, $data)) {
                continue;
            }
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $attributes[$key] = $data[$key];
        }

        return $attributes;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function fillRouteAttributes(ApiRoute $route, array $data, ApiConnector $connector): void
    {
        $route->name = $data['name'] ?? $route->name;
        $route->description = array_key_exists('description', $data) ? $data['description'] : $route->description;
        $route->http_method = $data['http_method'] ?? $route->http_method;
        $route->url = $data['url'] ?? $route->url;
        $route->auth_profile_id = array_key_exists('auth_profile_id', $data)
            ? $data['auth_profile_id']
            : $route->auth_profile_id;
        $route->mode = $data['mode'] ?? $route->mode;
        $route->timeout_ms = array_key_exists('timeout_ms', $data) ? $data['timeout_ms'] : $route->timeout_ms;
        $route->cache_ttl_s = array_key_exists('cache_ttl_s', $data) ? $data['cache_ttl_s'] : $route->cache_ttl_s;
        $route->rate_limit = array_key_exists('rate_limit', $data) ? $data['rate_limit'] : $route->rate_limit;
        if (array_key_exists('output_transform', $data)) {
            $route->output_transform = $this->arrayOrNull($data['output_transform']);
        }

        $route->slug = $this->resolveSlug($data, $route);
    }

    /**
     * Derive the slug: an explicit non-empty slug wins; otherwise normalise the
     * name. Keeps an existing slug on update when neither is supplied.
     *
     * @param  array<string,mixed>  $data
     */
    private function resolveSlug(array $data, ApiRoute $route): string
    {
        $explicit = isset($data['slug']) && is_string($data['slug']) ? trim($data['slug']) : '';
        if ($explicit !== '') {
            return $this->toolGenerator->normalizeSlug($explicit);
        }

        if ($route->slug !== '' && $route->exists) {
            return $route->slug;
        }

        $name = $data['name'] ?? $route->name ?? '';

        return $this->toolGenerator->normalizeSlug((string) $name);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return list<array<string,mixed>>
     */
    private function paramList(array $data): array
    {
        $params = $data['parameters'] ?? [];

        return is_array($params) ? array_values($params) : [];
    }

    /**
     * Replace (or seed) the route's parameters from the request payload.
     *
     * @param  list<array<string,mixed>>  $parameters
     */
    private function syncParameters(ApiRoute $route, array $parameters, bool $replace = false): void
    {
        if ($replace) {
            $route->parameters()->delete();
        }

        foreach ($parameters as $index => $param) {
            $model = new ApiRouteParameter;
            $model->api_route_id = $route->id;
            $model->tenant_id = $route->tenant_id;
            $model->name = (string) $param['name'];
            $model->location = $param['location'];
            $model->source = $param['source'];
            $model->type = $param['type'] ?? ParamType::String->value;
            $model->required = (bool) ($param['required'] ?? false);
            $model->value = $param['value'] ?? null;
            $model->secret_ref = $param['secret_ref'] ?? null;
            $model->description = $param['description'] ?? null;
            $model->sort_order = isset($param['sort_order']) ? (int) $param['sort_order'] : $index;
            $this->persist($model);
        }
    }

    /**
     * Build the persisted `param_mapping` ({name → {location, source, value?,
     * ref?}}) consumed by the runtime planner. Only fixed params carry `value`;
     * only secret params carry `ref` (the secret_ref). LLM params carry neither.
     *
     * @param  list<array<string,mixed>>  $parameters
     * @return array<string,array<string,mixed>>
     */
    private function buildParamMapping(array $parameters): array
    {
        $mapping = [];
        foreach ($parameters as $param) {
            $name = (string) $param['name'];
            $source = (string) $param['source'];
            $entry = [
                'location' => (string) $param['location'],
                'source' => $source,
            ];

            if ($source === ParamSource::Fixed->value && array_key_exists('value', $param)) {
                $entry['value'] = $param['value'];
            }

            if ($source === ParamSource::Secret->value && array_key_exists('secret_ref', $param)) {
                $entry['ref'] = $param['secret_ref'];
            }

            $mapping[$name] = $entry;
        }

        return $mapping;
    }

    private function connectorOf(ApiRoute $route): ApiConnector
    {
        return ApiConnector::forTenant($route->tenant_id)
            ->findOrFail($route->api_connector_id);
    }

    private function loadRoute(ApiRoute $route): ApiRoute
    {
        return $route->fresh(['parameters']) ?? $route;
    }

    /**
     * @throws RuntimeException with code 422 when the change is rejected (R28).
     */
    private function assertProjectKeyChangeAllowed(ApiConnector $connector, mixed $newProjectKey): void
    {
        $current = $connector->project_key;
        $incoming = is_string($newProjectKey) ? $newProjectKey : ($newProjectKey === null ? null : (string) $newProjectKey);

        if ($current === $incoming) {
            return;
        }

        // Equivalent to $connector->routes()->forTenant(...): the relation scopes
        // by api_connector_id; forTenant() is only resolvable as a static head.
        $hasRoutes = ApiRoute::forTenant($connector->tenant_id)
            ->where('api_connector_id', $connector->id)
            ->exists();
        if ($hasRoutes) {
            throw new RuntimeException(
                'Cannot change project_key while the connector has routes: it would desync the route project scope. Remove the routes first.',
                422,
            );
        }
    }

    /**
     * R4: a side-effecting save() that returns false is a real failure.
     */
    private function persist(Model $model): void
    {
        if (! $model->save()) {
            throw new RuntimeException('Failed to persist '.class_basename($model).'.');
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function arrayOrNull(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        return $value === [] ? null : $value;
    }

    private function currentTenant(): string
    {
        return $this->tenant->current();
    }
}
