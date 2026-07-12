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
use Padosoft\AskMyDocsConnectorApi\Support\ParamSource;
use Padosoft\AskMyDocsConnectorApi\Support\ParamType;
use Padosoft\AskMyDocsConnectorApi\Support\RelationMapper;
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

    /**
     * Fire the route (non-persisting dry run) and return a deterministically
     * REDUCED view of the response (spec item 3 no-AI half) — every array
     * truncated to a few representative items so the whole structure reads
     * start-to-end. The reduction runs only on a JSON body; a non-JSON / failed
     * call returns the raw {@see TestResult} with `reduced=null`. `analysis`
     * stays null here — the AI narration is layered on in P2.
     *
     * @param  array<string,mixed>  $exampleArgs
     * @return array{result: TestResult, reduced: mixed, notes: list<array<string,mixed>>, analysis: ?string}
     */
    public function analyzeRoute(ApiRoute $route, array $exampleArgs = []): array
    {
        $route->loadMissing('parameters');
        $result = $this->tester->dryRun($route, $exampleArgs);

        $reduced = null;
        $notes = [];
        $analysis = null;
        if ($result->isJson) {
            $reduction = $this->structureReducer->reduce($result->body);
            $reduced = $reduction['reduced'];
            $notes = $reduction['notes'];

            // AI narration of the reduced structure — optional, best-effort, and
            // gated (llm_assist off / Null analyst → stays null; the reduced view
            // is always shown regardless).
            if ((bool) config('connector-api.llm_assist.enabled', true)) {
                $analysis = $this->analyst->analyze([
                    'method' => $route->http_method->value,
                    'url' => $route->url,
                    'reduced' => $reduced,
                    'notes' => $notes,
                ]);
            }
        }

        return [
            'result' => $result,
            'reduced' => $reduced,
            'notes' => $notes,
            'analysis' => $analysis,
        ];
    }

    /**
     * Guess the endpoint's pagination scheme (spec item 4): a non-persisting
     * dryRun → the deterministic {@see PaginationDetector} → the AI fallback when
     * unclear (gated by llm_assist). Returns a config the operator confirms/edits
     * then saves via {@see updateRoute}; nothing is persisted here.
     *
     * @param  array<string,mixed>  $exampleArgs
     * @return array{config: array<string,mixed>|null, source: string}
     */
    public function detectPagination(ApiRoute $route, array $exampleArgs = []): array
    {
        $route->loadMissing('parameters');
        $result = $this->tester->dryRun($route, $exampleArgs);
        if (! $result->isJson) {
            return ['config' => null, 'source' => 'none'];
        }

        $config = $this->paginationDetector->detect($route, $result->body);
        if ($config !== null) {
            return ['config' => $config, 'source' => 'heuristic'];
        }

        if ((bool) config('connector-api.llm_assist.enabled', true)) {
            $reduced = $this->structureReducer->reduce($result->body)['reduced'];
            $aiConfig = $this->analyst->detectPagination([
                'method' => $route->http_method->value,
                'url' => $route->url,
                'reduced' => $reduced,
            ]);
            if ($aiConfig !== null) {
                return ['config' => $aiConfig, 'source' => 'ai'];
            }
        }

        return ['config' => null, 'source' => 'none'];
    }

    /**
     * Fire two pages with the given pagination config and report whether page 2
     * actually advances (spec item 5). Page-number → increments `page_param`;
     * cursor → reads the next cursor from page 1's body via `next_cursor_path`
     * and resends it. Non-persisting; SSRF still fires inside each dryRun.
     *
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>  $exampleArgs
     * @return array{pages: list<array<string,mixed>>, distinct: bool, note: string}
     */
    public function testPagination(ApiRoute $route, array $config, array $exampleArgs = []): array
    {
        $route->loadMissing('parameters');
        $type = is_string($config['type'] ?? null) ? $config['type'] : 'none';
        $itemsPath = is_string($config['items_path'] ?? null)
            ? $config['items_path']
            : (is_string($route->items_path) ? $route->items_path : '');

        if ($type === 'page') {
            $pageParam = (string) ($config['page_param'] ?? 'page');
            $start = (int) ($config['start_page'] ?? 1);
            $r1 = $this->tester->dryRun($route, $exampleArgs, [$pageParam => $start]);
            $r2 = $this->tester->dryRun($route, $exampleArgs, [$pageParam => $start + 1]);

            return $this->paginationVerdict($r1, $r2, $itemsPath, "pagina {$start} → ".($start + 1));
        }

        if ($type === 'cursor') {
            $r1 = $this->tester->dryRun($route, $exampleArgs);
            $cursor = $r1->isJson && isset($config['next_cursor_path'])
                ? $this->valueAtPath($r1->body, (string) $config['next_cursor_path'])
                : null;

            if (! is_string($cursor) && ! is_int($cursor) || $cursor === '') {
                $where = (string) ($config['next_cursor_path'] ?? $config['next_url_path'] ?? '?');

                return [
                    'pages' => [$this->pageSummary($r1, $itemsPath)],
                    'distinct' => false,
                    'note' => "Cursore non trovato in `{$where}` — forse è l'ultima pagina o il path va corretto.",
                ];
            }

            $cursorParam = (string) ($config['cursor_param'] ?? 'cursor');
            $r2 = $this->tester->dryRun($route, $exampleArgs, [$cursorParam => (string) $cursor]);

            return $this->paginationVerdict($r1, $r2, $itemsPath, 'cursor');
        }

        return ['pages' => [], 'distinct' => false, 'note' => 'Tipo di paginazione non impostato.'];
    }

    /**
     * @return array{pages: list<array<string,mixed>>, distinct: bool, note: string}
     */
    private function paginationVerdict(TestResult $r1, TestResult $r2, string $itemsPath, string $label): array
    {
        $items1 = $r1->isJson ? $this->relationMapper->itemsAt($r1->body, $itemsPath) : [];
        $items2 = $r2->isJson ? $this->relationMapper->itemsAt($r2->body, $itemsPath) : [];
        $distinct = $r2->ok && $items2 !== [] && json_encode($items2) !== json_encode($items1);

        if (! $r2->ok) {
            $note = "Pagina 2 ha fallito (HTTP {$r2->status}).";
        } elseif ($distinct) {
            $note = "Le due pagine restituiscono item diversi ({$label}) — la paginazione funziona.";
        } else {
            $note = "Pagina 2 identica o vuota ({$label}) — la paginazione potrebbe non essere applicata.";
        }

        return [
            'pages' => [$this->pageSummary($r1, $itemsPath), $this->pageSummary($r2, $itemsPath)],
            'distinct' => $distinct,
            'note' => $note,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function pageSummary(TestResult $result, string $itemsPath): array
    {
        $items = $result->isJson ? $this->relationMapper->itemsAt($result->body, $itemsPath) : [];

        return [
            'ok' => $result->ok,
            'status' => $result->status,
            'item_count' => count($items),
        ];
    }

    private function valueAtPath(mixed $body, string $path): mixed
    {
        if (! is_array($body) || $path === '') {
            return null;
        }

        $node = $body;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * Workbench "Cerca" (spec item 6) — fire the route with the operator's search
     * parameter values and return the raw response. Thin over {@see ApiRouteTester::dryRun}
     * (non-persisting); the search params are just the route's llm arguments.
     *
     * @param  array<string,mixed>  $searchArgs
     */
    public function testSearch(ApiRoute $route, array $searchArgs): TestResult
    {
        $route->loadMissing('parameters');

        return $this->tester->dryRun($route, $searchArgs);
    }

    /**
     * "Configura con AI" — one pass that proposes the FULL route configuration
     * from a test call: deterministic endpoint_type/items_path + heuristic
     * pagination, plus an AI suggestion for the tool name/description, the request
     * parameters and (fallback) pagination. Returns a SUGGESTION the operator
     * reviews + applies via {@see updateRoute}; nothing is persisted here. A
     * non-JSON / failed call yields `suggestion = null` (R14).
     *
     * @param  array<string,mixed>  $exampleArgs
     * @return array{result: TestResult, suggestion: array<string,mixed>|null}
     */
    public function autoConfigure(ApiRoute $route, array $exampleArgs = []): array
    {
        $route->loadMissing('parameters');
        $result = $this->tester->dryRun($route, $exampleArgs);
        if (! $result->isJson) {
            return ['result' => $result, 'suggestion' => null];
        }

        $classification = $this->schemaInferrer->classifyEndpoint($result->body);
        $pagination = $this->paginationDetector->detect($route, $result->body);

        $ai = null;
        if ((bool) config('connector-api.llm_assist.enabled', true)) {
            $reduced = $this->structureReducer->reduce($result->body)['reduced'];
            $ai = $this->analyst->suggestConfiguration([
                'method' => $route->http_method->value,
                'url' => $route->url,
                'reduced' => $reduced,
            ]);
        }

        return [
            'result' => $result,
            'suggestion' => [
                'endpoint_type' => $classification['type']->value,
                'items_path' => $classification['items_path'],
                'pagination' => $pagination ?? ($ai['pagination'] ?? null),
                'tool_name' => $ai['tool_name'] ?? null,
                'tool_description' => $ai['tool_description'] ?? null,
                'parameters' => $ai['parameters'] ?? [],
            ],
        ];
    }

    /**
     * ONE-SHOT "Configura con AI": detect → apply → final test. Runs
     * {@see autoConfigure}, PERSISTS the suggestion onto the route (parameters
     * forced non-required so the verification call can't fail on a missing arg —
     * the operator tightens later), runs the real test (which infers the schema +
     * promotes draft→tested), and, when a pagination scheme was set, verifies it
     * advances. Everything the workbench used to need across many steps, in one.
     *
     * @param  array<string,mixed>  $exampleArgs
     * @return array{applied: array<string,mixed>|null, final_test: TestResult, pagination_test: array<string,mixed>|null, source: string}
     */
    public function applyAiConfiguration(ApiRoute $route, array $exampleArgs = [], ?string $openApiUrl = null): array
    {
        $route->loadMissing('parameters');

        // Prefer the OpenAPI contract when a spec URL is given (authoritative,
        // works even when a live call would fail on auth); fall back to the
        // response-based agent. Importer failures (SSRF / unparseable) bubble as 422.
        $suggestion = null;
        $source = 'response';
        if (is_string($openApiUrl) && $openApiUrl !== '') {
            $suggestion = $this->openApiImporter->configForRoute($openApiUrl, $route);
            if ($suggestion !== null) {
                $source = 'openapi';
            }
        }

        if ($suggestion === null) {
            $auto = $this->autoConfigure($route, $exampleArgs);
            /** @var TestResult $probe */
            $probe = $auto['result'];
            $suggestion = $auto['suggestion'];
            if ($suggestion === null) {
                return ['applied' => null, 'final_test' => $probe, 'pagination_test' => null, 'source' => $source];
            }
        }

        $payload = [
            'items_path' => $suggestion['items_path'] ?? null,
            'pagination' => $suggestion['pagination'] ?? null,
            // Non-required so the immediate verification call can't fail on a
            // missing llm arg; the operator can re-mark them required afterwards.
            'parameters' => array_map(
                static fn (array $p): array => ['required' => false] + $p,
                is_array($suggestion['parameters'] ?? null) ? $suggestion['parameters'] : [],
            ),
        ];
        if (($suggestion['endpoint_type'] ?? 'unknown') !== 'unknown') {
            $payload['endpoint_type'] = $suggestion['endpoint_type'];
        }
        if (is_string($suggestion['tool_name'] ?? null) && $suggestion['tool_name'] !== '') {
            $payload['slug'] = $suggestion['tool_name'];
        }
        if (is_string($suggestion['tool_description'] ?? null) && $suggestion['tool_description'] !== '') {
            $payload['description'] = $suggestion['tool_description'];
        }

        $route = $this->updateRoute($route, $payload);

        // Final test — infers the input/output schema + tool definition and
        // promotes the route to `tested`, ready to activate.
        $final = $this->testRoute($route, $exampleArgs);
        /** @var TestResult $finalResult */
        $finalResult = $final['result'];

        $paginationTest = null;
        $pagination = $suggestion['pagination'] ?? null;
        if (is_array($pagination) && ($pagination['type'] ?? 'none') !== 'none') {
            $fresh = $route->fresh(['parameters']) ?? $route;
            $paginationTest = $this->testPagination($fresh, $pagination, $exampleArgs);
        }

        return ['applied' => $suggestion, 'final_test' => $finalResult, 'pagination_test' => $paginationTest, 'source' => $source];
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
