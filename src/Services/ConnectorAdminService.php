<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorApi\Exceptions\ApiConnectorException;
use Padosoft\AskMyDocsConnectorApi\Models\ApiAuthProfile;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteParameter;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteRelation;
use Padosoft\AskMyDocsConnectorApi\Support\EndpointType;
use Padosoft\AskMyDocsConnectorApi\Support\HttpMethod;
use Padosoft\AskMyDocsConnectorApi\Support\ParamSource;
use Padosoft\AskMyDocsConnectorApi\Support\ParamType;
use Padosoft\AskMyDocsConnectorApi\Support\RelationMapper;
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
        private readonly RelationMapper $relationMapper,
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

    public function findRelation(int $id): ApiRouteRelation
    {
        return ApiRouteRelation::forTenant($this->currentTenant())
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
        $query = ApiConnector::forTenant($this->currentTenant())
            ->with(['routes', 'relations.listRoute:id,slug', 'relations.detailRoute:id,slug']);
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
        // Remove relations where this route is either side BEFORE deleting it.
        // The DB FK cascades too, but SQLite only enforces it with PRAGMA
        // foreign_keys ON, so the app-side sweep keeps correctness driver-agnostic.
        ApiRouteRelation::forTenant($route->tenant_id)
            ->where(function ($q) use ($route): void {
                $q->where('list_route_id', $route->id)
                    ->orWhere('detail_route_id', $route->id);
            })
            ->delete();

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

        // Auto-detect the endpoint taxonomy (Lista vs Dettaglio) from the live
        // response — UNLESS the operator locked an explicit override, in which
        // case their choice (and any manual items_path) is preserved.
        if (! $route->endpoint_type_locked) {
            $classification = $this->schemaInferrer->classifyEndpoint($result->body);
            $route->endpoint_type = $classification['type'];
            $route->items_path = $classification['items_path'];
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
     | Relations (List → Detail) — spec Obj 3
     * -------------------------------------------------------------------- */

    /**
     * @return Collection<int,ApiRouteRelation>
     */
    public function listRelations(ApiConnector $connector): Collection
    {
        $query = ApiRouteRelation::forTenant($connector->tenant_id)
            ->where('api_connector_id', $connector->id)
            ->with(['listRoute', 'detailRoute']);
        $query->orderBy('sort_order')->orderBy('id');

        return $query->get();
    }

    /**
     * @param  array<string,mixed>  $data
     *
     * @throws RuntimeException 422 on an invalid pair / mapping / duplicate
     */
    public function createRelation(ApiConnector $connector, array $data): ApiRouteRelation
    {
        $list = $this->findRoute((int) ($data['list_route_id'] ?? 0));
        $detail = $this->findRoute((int) ($data['detail_route_id'] ?? 0));
        $fieldMap = $this->normalizeFieldMap($data['field_map'] ?? []);
        $this->assertRelationValid($connector, $list, $detail, $fieldMap);

        $relation = new ApiRouteRelation;
        $relation->tenant_id = $connector->tenant_id;
        $relation->api_connector_id = $connector->id;
        $relation->list_route_id = $list->id;
        $relation->detail_route_id = $detail->id;
        $relation->name = isset($data['name']) ? (string) $data['name'] : null;
        $relation->description = isset($data['description']) ? (string) $data['description'] : null;
        $relation->field_map = $fieldMap;
        $relation->sort_order = (int) ($data['sort_order'] ?? 0);
        $this->persist($relation);

        return $this->loadRelation($relation);
    }

    /**
     * @param  array<string,mixed>  $data
     *
     * @throws RuntimeException 422 on an invalid pair / mapping / duplicate
     */
    public function updateRelation(ApiRouteRelation $relation, array $data): ApiRouteRelation
    {
        $connector = ApiConnector::forTenant($relation->tenant_id)
            ->findOrFail($relation->api_connector_id);

        $list = array_key_exists('list_route_id', $data)
            ? $this->findRoute((int) $data['list_route_id'])
            : $this->findRoute($relation->list_route_id);
        $detail = array_key_exists('detail_route_id', $data)
            ? $this->findRoute((int) $data['detail_route_id'])
            : $this->findRoute($relation->detail_route_id);
        $fieldMap = array_key_exists('field_map', $data)
            ? $this->normalizeFieldMap($data['field_map'])
            : $relation->field_map;

        $this->assertRelationValid($connector, $list, $detail, $fieldMap, ignoreRelationId: $relation->id);

        $relation->list_route_id = $list->id;
        $relation->detail_route_id = $detail->id;
        if (array_key_exists('name', $data)) {
            $relation->name = $data['name'] === null ? null : (string) $data['name'];
        }
        if (array_key_exists('description', $data)) {
            $relation->description = $data['description'] === null ? null : (string) $data['description'];
        }
        $relation->field_map = $fieldMap;
        if (array_key_exists('sort_order', $data)) {
            $relation->sort_order = (int) $data['sort_order'];
        }
        $this->persist($relation);

        return $this->loadRelation($relation);
    }

    public function deleteRelation(ApiRouteRelation $relation): void
    {
        if (! $relation->delete()) {
            throw new RuntimeException('Failed to delete relation.');
        }
    }

    /**
     * Admin drill-test: take a single LIST item (client-supplied, or the item at
     * `$itemIndex` in the list route's last test payload), apply the relation's
     * field_map to build the detail route's arguments, and fire a NON-persisted
     * raw call to the detail route. SSRF + auth still apply (inside dryRun); the
     * detail route's last_test_* is NOT touched.
     *
     * @param  array<string,mixed>|null  $listItem
     * @return array{arguments: array<string,mixed>, result: TestResult}
     *
     * @throws RuntimeException 422 when the item is missing or the mapping does not
     *                          fit the chosen item (R14 — never a silent null)
     */
    public function drillTest(ApiRouteRelation $relation, ?array $listItem, ?int $itemIndex): array
    {
        // detailRoute + listRoute are guaranteed by the NOT-NULL FKs + cascade.
        $relation->loadMissing(['detailRoute.parameters', 'listRoute']);
        $item = $this->resolveDrillItem($relation, $listItem, $itemIndex);

        try {
            $arguments = $this->relationMapper->mapArguments($item, $relation->field_map);
        } catch (ApiConnectorException $e) {
            // A mapping that doesn't fit the chosen item is a client-fixable 422.
            throw new RuntimeException($e->getMessage(), 422);
        }

        $result = $this->tester->dryRun($relation->detailRoute, $arguments);

        return ['arguments' => $arguments, 'result' => $result];
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
        $this->applyEndpointType($route, $data);

        $route->slug = $this->resolveSlug($data, $route);
    }

    /**
     * Apply the operator's endpoint-taxonomy choice.
     *
     * The wire contract is `endpoint_type ∈ {auto, list, detail}`:
     *  - `auto` (or null/'') UNLOCKS detection — testRoute owns endpoint_type +
     *    items_path from the next live response.
     *  - `list`/`detail` LOCK an explicit override the detector must not clobber.
     * `items_path` is only meaningful for a list; a supplied value is stored
     * verbatim ('' = top-level array).
     *
     * @param  array<string,mixed>  $data
     */
    private function applyEndpointType(ApiRoute $route, array $data): void
    {
        if (array_key_exists('endpoint_type', $data)) {
            $choice = $data['endpoint_type'];
            if ($choice === EndpointType::List->value || $choice === EndpointType::Detail->value) {
                $route->endpoint_type = EndpointType::from($choice);
                $route->endpoint_type_locked = true;
            } else {
                // 'auto' / null / '' / anything else → hand control back to the detector.
                $route->endpoint_type_locked = false;
            }
        }

        if (array_key_exists('items_path', $data)) {
            $value = $data['items_path'];
            $route->items_path = is_string($value) ? $value : null;
        }
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

    private function loadRelation(ApiRouteRelation $relation): ApiRouteRelation
    {
        return $relation->fresh(['listRoute', 'detailRoute']) ?? $relation;
    }

    /**
     * @param  list<array{from:string,to_param:string,to_location?:string}>  $fieldMap
     *
     * @throws RuntimeException 422 when the pair or the mapping is invalid
     */
    private function assertRelationValid(
        ApiConnector $connector,
        ApiRoute $list,
        ApiRoute $detail,
        array $fieldMap,
        ?int $ignoreRelationId = null,
    ): void {
        if ($list->id === $detail->id) {
            throw new RuntimeException('A relation must link two DIFFERENT routes.', 422);
        }
        if ($list->api_connector_id !== $connector->id || $detail->api_connector_id !== $connector->id) {
            throw new RuntimeException('Both the list and detail routes must belong to this connector.', 422);
        }
        if (! $list->isList()) {
            throw new RuntimeException('The list_route must have endpoint_type=list.', 422);
        }
        if (! $detail->isDetail()) {
            throw new RuntimeException('The detail_route must have endpoint_type=detail.', 422);
        }
        if ($fieldMap === []) {
            throw new RuntimeException('field_map cannot be empty.', 422);
        }

        // R5: every target must be an LLM parameter of the detail route — a
        // fixed/secret param (or an undeclared token) cannot be injected from a
        // list item. A path token like {id} is itself declared as an llm param.
        $detail->loadMissing('parameters');
        $llmParamNames = $detail->parameters
            ->filter(fn (ApiRouteParameter $p): bool => $p->source === ParamSource::Llm)
            ->map(fn (ApiRouteParameter $p): string => $p->name)
            ->all();

        foreach ($fieldMap as $entry) {
            $toParam = $entry['to_param'];
            if (! in_array($toParam, $llmParamNames, true)) {
                throw new RuntimeException(
                    "field_map target '{$toParam}' is not an LLM parameter of the detail route.",
                    422,
                );
            }
        }

        $duplicate = ApiRouteRelation::forTenant($connector->tenant_id)
            ->where('list_route_id', $list->id)
            ->where('detail_route_id', $detail->id)
            ->when($ignoreRelationId !== null, fn ($q) => $q->whereKeyNot($ignoreRelationId))
            ->exists();
        if ($duplicate) {
            throw new RuntimeException('A relation between these two routes already exists.', 422);
        }
    }

    /**
     * Normalise the field_map into an ordered list of
     * `{from, to_param, to_location?}`, dropping incomplete rows.
     *
     * @return list<array{from:string,to_param:string,to_location?:string}>
     */
    private function normalizeFieldMap(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $from = trim((string) ($entry['from'] ?? ''));
            $toParam = trim((string) ($entry['to_param'] ?? ''));
            if ($from === '' || $toParam === '') {
                continue;
            }

            $mapped = ['from' => $from, 'to_param' => $toParam];
            $location = $entry['to_location'] ?? null;
            if (is_string($location) && $location !== '') {
                $mapped['to_location'] = $location;
            }
            $out[] = $mapped;
        }

        return $out;
    }

    /**
     * Resolve the single list item to drill from: an explicit client-supplied
     * item wins; otherwise the item at `$itemIndex` (default 0) of the list
     * route's persisted last_test_payload, unwrapped at its items_path.
     *
     * @param  array<string,mixed>|null  $listItem
     * @return array<string,mixed>
     *
     * @throws RuntimeException 422 when no item can be resolved
     */
    private function resolveDrillItem(ApiRouteRelation $relation, ?array $listItem, ?int $itemIndex): array
    {
        if ($listItem !== null) {
            return $listItem;
        }

        $list = $relation->listRoute;
        $items = $this->relationMapper->itemsAt($list->last_test_payload, $list->items_path);
        $index = $itemIndex ?? 0;
        if (! array_key_exists($index, $items) || ! is_array($items[$index])) {
            throw new RuntimeException(
                'No list item at that index — test the list route first to populate its items.',
                422,
            );
        }

        return $items[$index];
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
