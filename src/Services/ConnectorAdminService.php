<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorApi\Contracts\ResponseAnalyst;
use Padosoft\AskMyDocsConnectorApi\Exceptions\ApiConnectorException;
use Padosoft\AskMyDocsConnectorApi\Models\ApiAuthProfile;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteParameter;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteRelation;
use Padosoft\AskMyDocsConnectorApi\Support\EndpointType;
use Padosoft\AskMyDocsConnectorApi\Support\HttpMethod;
use Padosoft\AskMyDocsConnectorApi\Support\OpenApiImporter;
use Padosoft\AskMyDocsConnectorApi\Support\PaginationDetector;
use Padosoft\AskMyDocsConnectorApi\Support\ParamLocation;
use Padosoft\AskMyDocsConnectorApi\Support\ParamSource;
use Padosoft\AskMyDocsConnectorApi\Support\ParamType;
use Padosoft\AskMyDocsConnectorApi\Support\RelationMapper;
use Padosoft\AskMyDocsConnectorApi\Support\RouteConfig;
use Padosoft\AskMyDocsConnectorApi\Support\RouteConfigSchema;
use Padosoft\AskMyDocsConnectorApi\Support\RouteMode;
use Padosoft\AskMyDocsConnectorApi\Support\RouteStatus;
use Padosoft\AskMyDocsConnectorApi\Support\StructureReducer;
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
        private readonly StructureReducer $structureReducer,
        private readonly ResponseAnalyst $analyst,
        private readonly PaginationDetector $paginationDetector,
        private readonly OpenApiImporter $openApiImporter,
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
        $definition = $this->toolGenerator->generate(
            $route,
            $inputSchema,
            $result->body,
            $this->relationContextFor($route),
        );

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

        $definition = $this->toolGenerator->generate(
            $route,
            $inputSchema,
            $route->last_test_payload,
            $this->relationContextFor($route),
        );
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
     | Config JSON (canonical) — the AI-produced pivot for a route
     * -------------------------------------------------------------------- */

    /**
     * Dry-run a config JSON that has NOT been persisted yet (the modal's "Testa"
     * — works in create mode too), and classify the live response.
     *
     * Builds a transient (unsaved) route from the config, fires the real call
     * (SSRF/planner/auth all apply), and — on a JSON body — reports the
     * deterministically classified endpoint_type/items_path + detected
     * pagination so the modal can offer them without a second round-trip.
     *
     * @param  array<string,mixed>  $config  a (grouped) config JSON
     * @param  array<string,mixed>  $exampleArgs
     * @return array{test: TestResult, endpoint_type: string, items_path: ?string, detected_pagination: ?array<string,mixed>, item_count: ?int}
     */
    public function testConfig(ApiConnector $connector, array $config, array $exampleArgs = []): array
    {
        $route = $this->transientRouteFromConfig($connector, RouteConfigSchema::sanitize($config) ?? $config);
        $result = $this->tester->dryRun($route, $exampleArgs);

        $type = 'unknown';
        $itemsPath = null;
        $pagination = null;
        $itemCount = null;
        if ($result->isJson) {
            $classification = $this->schemaInferrer->classifyEndpoint($result->body);
            $type = $classification['type']->value;
            $itemsPath = $classification['items_path'];
            $pagination = $this->paginationDetector->detect($route, $result->body);
            $itemCount = count($this->relationMapper->itemsAt($result->body, is_string($itemsPath) ? $itemsPath : ''));
        }

        return [
            'test' => $result,
            'endpoint_type' => $type,
            'items_path' => $itemsPath,
            'detected_pagination' => $pagination,
            'item_count' => $itemCount,
        ];
    }

    /**
     * "Configura con AI" over the canonical config JSON — the single AI pass.
     *
     * Prefer an OpenAPI contract when a spec URL is given (authoritative, no live
     * call needed); otherwise dry-run the current config, hand the reduced sample
     * + the target schema + a deterministic seed to {@see ResponseAnalyst::produceConfig()},
     * then let the deterministic classifier/detector WIN on the structural fields.
     * Returns the produced config JSON + a final dry-run of it (the "test finale")
     * — it does NOT persist; the operator reviews the filled form and saves.
     *
     * @param  array<string,mixed>  $config  the current (grouped) config JSON
     * @param  array<string,mixed>  $exampleArgs
     * @return array{config: array<string,mixed>|null, final_test: TestResult, source: string}
     */
    public function produceConfig(ApiConnector $connector, array $config, array $exampleArgs = [], ?string $openApiUrl = null): array
    {
        $input = RouteConfigSchema::sanitize($config) ?? $config;
        $transient = $this->transientRouteFromConfig($connector, $input);

        // OpenAPI producer: authoritative, works behind auth, no live call.
        if (is_string($openApiUrl) && $openApiUrl !== '') {
            $suggestion = $this->openApiImporter->configForRoute($openApiUrl, $transient);
            if ($suggestion !== null) {
                $produced = RouteConfigSchema::sanitize($this->suggestionToConfig($suggestion, $input));

                return [
                    'config' => $produced,
                    'final_test' => $this->dryRunProducedConfig($connector, $produced ?? $input, $exampleArgs),
                    'source' => 'openapi',
                ];
            }
        }

        // Response producer: sample the endpoint, then AI + deterministic-wins.
        $result = $this->tester->dryRun($transient, $exampleArgs);
        if (! $result->isJson) {
            return ['config' => null, 'final_test' => $result, 'source' => 'none'];
        }

        $reduction = $this->structureReducer->reduce($result->body);
        $seed = $this->deterministicSeed($transient, $result->body);

        $aiConfig = null;
        if ((bool) config('connector-api.llm_assist.enabled', true)) {
            $aiConfig = $this->analyst->produceConfig([
                'method' => $transient->http_method->value,
                'url' => $transient->url,
                'example_args' => $exampleArgs,
                'reduced' => $reduction['reduced'],
                'notes' => $reduction['notes'],
                'schema' => RouteConfigSchema::schema(),
                'seed' => $seed,
                'current' => $input,
            ]);
        }

        $produced = RouteConfigSchema::sanitize($this->mergeProducedConfig($input, $aiConfig, $seed));

        return [
            'config' => $produced,
            'final_test' => $this->dryRunProducedConfig($connector, $produced ?? $input, $exampleArgs),
            'source' => 'response',
        ];
    }

    /**
     * Build a transient (UNSAVED) route from a config JSON so the tester's
     * planner/executor/SSRF path can dry-run it without persistence. In-memory
     * params + connector relation are set so RequestPlanner + effectiveAuthProfile
     * resolve exactly as they would for a saved route.
     *
     * @param  array<string,mixed>  $config
     */
    private function transientRouteFromConfig(ApiConnector $connector, array $config): ApiRoute
    {
        $flat = RouteConfig::applyToRoute($config);

        $route = new ApiRoute;
        $route->tenant_id = $connector->tenant_id;
        $route->api_connector_id = $connector->id;
        $route->project_key = $connector->projectScope();
        $route->name = (string) ($flat['name'] ?? '');
        $route->slug = '';
        $route->description = $flat['description'] ?? null;
        $route->http_method = $flat['http_method'] ?? HttpMethod::GET->value;
        $route->url = (string) ($flat['url'] ?? '');
        $route->auth_profile_id = $flat['auth_profile_id'] ?? null;
        $route->mode = $flat['mode'] ?? RouteMode::Tool->value;
        $route->status = RouteStatus::Draft;
        $route->endpoint_type = EndpointType::Unknown;
        $route->endpoint_type_locked = false;
        $route->timeout_ms = $flat['timeout_ms'] ?? null;
        $route->cache_ttl_s = $flat['cache_ttl_s'] ?? null;
        $route->rate_limit = $flat['rate_limit'] ?? null;
        $route->output_transform = $this->arrayOrNull($flat['output_transform'] ?? null);
        $route->pagination = $this->arrayOrNull($flat['pagination'] ?? null);
        $route->items_path = is_string($flat['items_path'] ?? null) ? $flat['items_path'] : null;

        $params = [];
        foreach ($flat['parameters'] ?? [] as $index => $p) {
            $model = new ApiRouteParameter;
            $model->tenant_id = $connector->tenant_id;
            $model->name = (string) ($p['name'] ?? '');
            $model->location = $p['location'] ?? ParamLocation::Query->value;
            $model->source = $p['source'] ?? ParamSource::Llm->value;
            $model->type = $p['type'] ?? ParamType::String->value;
            $model->required = (bool) ($p['required'] ?? false);
            $model->value = $p['value'] ?? null;
            $model->secret_ref = $p['secret_ref'] ?? null;
            $model->description = $p['description'] ?? null;
            $model->sort_order = isset($p['sort_order']) ? (int) $p['sort_order'] : $index;
            $params[] = $model;
        }
        $route->setRelation('parameters', new Collection($params));
        $route->setRelation('connector', $connector);

        return $route;
    }

    /**
     * Dry-run a PRODUCED config as the "test finale". LLM params are forced
     * non-required so a freshly-inferred required arg missing from the example
     * args can't fail the verification call (the operator tightens later).
     *
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>  $exampleArgs
     */
    private function dryRunProducedConfig(ApiConnector $connector, array $config, array $exampleArgs): TestResult
    {
        $relaxed = $config;
        $params = $relaxed['request']['params'] ?? [];
        if (is_array($params)) {
            $relaxed['request']['params'] = array_map(
                static fn (mixed $p): mixed => is_array($p) ? ['required' => false] + $p : $p,
                $params,
            );
        }

        return $this->tester->dryRun($this->transientRouteFromConfig($connector, $relaxed), $exampleArgs);
    }

    /**
     * Deterministic seed for the AI: the classified endpoint_type/items_path +
     * the detected pagination, expressed as a partial config `response` group.
     *
     * @return array<string,mixed>
     */
    private function deterministicSeed(ApiRoute $transient, mixed $body): array
    {
        $classification = $this->schemaInferrer->classifyEndpoint($body);
        $type = $classification['type'];

        return [
            'response' => [
                'endpoint_type' => $type === EndpointType::Unknown ? 'auto' : $type->value,
                'items_path' => $classification['items_path'],
                'pagination' => $this->paginationDetector->detect($transient, $body),
            ],
        ];
    }

    /**
     * Merge the AI config (or the input, when AI is off) with the deterministic
     * seed — the classifier/detector WIN on the structural fields.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>|null  $aiConfig
     * @param  array<string,mixed>  $seed
     * @return array<string,mixed>
     */
    private function mergeProducedConfig(array $input, ?array $aiConfig, array $seed): array
    {
        $base = is_array($aiConfig) ? $aiConfig : $input;
        $seedResponse = is_array($seed['response'] ?? null) ? $seed['response'] : [];
        $response = is_array($base['response'] ?? null) ? $base['response'] : [];

        // Deterministic classification wins when it decided; otherwise keep base.
        if (($seedResponse['endpoint_type'] ?? 'auto') !== 'auto') {
            $response['endpoint_type'] = $seedResponse['endpoint_type'];
            $response['items_path'] = $seedResponse['items_path'] ?? null;
        }
        // Detected pagination wins; else keep whatever the AI/base proposed.
        if (($seedResponse['pagination'] ?? null) !== null) {
            $response['pagination'] = $seedResponse['pagination'];
        }
        $base['response'] = $response;

        return $base;
    }

    /**
     * Adapt the flat OpenAPI-importer suggestion into a grouped config JSON,
     * carrying request/auth/options from the current input config.
     *
     * @param  array<string,mixed>  $suggestion
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function suggestionToConfig(array $suggestion, array $input): array
    {
        $identity = is_array($input['identity'] ?? null) ? $input['identity'] : [];
        $request = is_array($input['request'] ?? null) ? $input['request'] : [];

        $params = [];
        foreach (is_array($suggestion['parameters'] ?? null) ? $suggestion['parameters'] : [] as $index => $p) {
            if (! is_array($p)) {
                continue;
            }
            $params[] = ['sort_order' => $index] + $p;
        }

        return [
            'identity' => [
                'name' => $suggestion['tool_name'] ?? ($identity['name'] ?? ''),
                'slug' => null,
                'description' => $suggestion['tool_description'] ?? ($identity['description'] ?? null),
                'mode' => $identity['mode'] ?? RouteMode::Tool->value,
            ],
            'request' => [
                'http_method' => $request['http_method'] ?? HttpMethod::GET->value,
                'url' => $request['url'] ?? '',
                'auth_profile_id' => $request['auth_profile_id'] ?? null,
                'params' => $params,
            ],
            'response' => [
                'endpoint_type' => ($suggestion['endpoint_type'] ?? 'unknown') !== 'unknown' ? $suggestion['endpoint_type'] : 'auto',
                'items_path' => $suggestion['items_path'] ?? null,
                'transform' => is_array($input['response'] ?? null) ? ($input['response']['transform'] ?? null) : null,
                'pagination' => $suggestion['pagination'] ?? null,
            ],
            'options' => is_array($input['options'] ?? null) ? $input['options'] : [],
        ];
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

        // Re-annotate both peers' tool definitions so the LLM chains them (Fase 3).
        $this->refreshToolDefinitionsByIds([$list->id, $detail->id]);

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

        // Peers before the change — re-annotated too if the pair is repointed.
        $previousPeerIds = [$relation->list_route_id, $relation->detail_route_id];

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

        $this->refreshToolDefinitionsByIds([...$previousPeerIds, $list->id, $detail->id]);

        return $this->loadRelation($relation);
    }

    public function deleteRelation(ApiRouteRelation $relation): void
    {
        $peerIds = [$relation->list_route_id, $relation->detail_route_id];
        if (! $relation->delete()) {
            throw new RuntimeException('Failed to delete relation.');
        }

        // The chain guidance must drop from both peers' descriptions.
        $this->refreshToolDefinitionsByIds($peerIds);
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
        if (array_key_exists('pagination', $data)) {
            $route->pagination = $this->arrayOrNull($data['pagination']);
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
     * The List→Detail relation context of a route (Fase 3): `inbound` = relations
     * where it is the DETAIL (each with the feeding list slug + field_map),
     * `outbound` = relations where it is the LIST (each with the drillable detail
     * slug). Feeds {@see ToolDefinitionGenerator::generate()} so the tool
     * descriptions guide the LLM to chain list→detail. Tenant-scoped (R30).
     *
     * @return array{inbound: list<array{list_slug: string, field_map: mixed}>, outbound: list<array{detail_slug: string}>}
     */
    private function relationContextFor(ApiRoute $route): array
    {
        $tenant = $route->tenant_id;

        $inbound = [];
        $inboundRelations = ApiRouteRelation::forTenant($tenant)
            ->where('detail_route_id', $route->id)
            ->with('listRoute:id,slug')
            ->get();
        foreach ($inboundRelations as $relation) {
            $inbound[] = ['list_slug' => $relation->listRoute->slug, 'field_map' => $relation->field_map];
        }

        $outbound = [];
        $outboundRelations = ApiRouteRelation::forTenant($tenant)
            ->where('list_route_id', $route->id)
            ->with('detailRoute:id,slug')
            ->get();
        foreach ($outboundRelations as $relation) {
            $outbound[] = ['detail_slug' => $relation->detailRoute->slug];
        }

        return ['inbound' => $inbound, 'outbound' => $outbound];
    }

    /**
     * Re-annotate the tool_definition of each given route from its current
     * relation context. Skips routes that are missing (cross-tenant / deleted) or
     * not yet tested (no input_schema to annotate). Side-effect only — never
     * throws for a stale peer.
     *
     * @param  list<int>  $routeIds
     */
    private function refreshToolDefinitionsByIds(array $routeIds): void
    {
        foreach (array_unique(array_filter($routeIds)) as $id) {
            $route = ApiRoute::forTenant($this->currentTenant())
                ->with('parameters')
                ->find((int) $id);
            if ($route === null) {
                continue;
            }

            $inputSchema = is_array($route->input_schema) ? $route->input_schema : null;
            if ($inputSchema === null) {
                continue; // never tested — nothing to annotate yet
            }

            $route->tool_definition = $this->toolGenerator->generate(
                $route,
                $inputSchema,
                $route->last_test_payload,
                $this->relationContextFor($route),
            );
            $this->persist($route);
        }
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
