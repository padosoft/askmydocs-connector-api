<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\AiConfigureApplyRequest;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\ProbeRequest;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\ProduceConfigRequest;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\StoreRouteRequest;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\TestConfigRequest;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\TestPaginationRequest;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\TestRouteRequest;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\TryRouteRequest;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\UpdateRouteRequest;
use Padosoft\AskMyDocsConnectorApi\Http\Resources\ApiRouteResource;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Services\ConnectorAdminService;
use Padosoft\AskMyDocsConnectorApi\Support\EndpointType;
use Padosoft\AskMyDocsConnectorApi\Support\RouteConfig;
use Padosoft\AskMyDocsConnectorApi\Support\RouteConfigSchema;
use Padosoft\AskMyDocsConnectorApi\Support\TestResult;
use RuntimeException;

/**
 * Thin admin controller for Rotte (routes) — CRUD + the test → schema → tool
 * pipeline + lifecycle transitions (spec §5/§6). All orchestration lives in
 * {@see ConnectorAdminService}; this controller adapts request → service →
 * resource and maps the 422 lifecycle/regenerate guards to a JSON failure.
 *
 * R14: a route never found 404s (tenant-scoped loader); a failed TEST call is a
 * valid outcome rendered as HTTP 200 with `ok:false` so the UI can diagnose it,
 * whereas a genuine missing route or an un-tested activate is a real error code.
 */
final class ApiRouteController extends Controller
{
    public function __construct(private readonly ConnectorAdminService $service) {}

    public function store(StoreRouteRequest $request, int $connector): JsonResponse
    {
        $connectorModel = $this->service->findConnector($connector);

        try {
            $route = $this->service->createRoute($connectorModel, $this->routeData($request->validated()));
        } catch (RuntimeException $e) {
            return $this->failure($e);
        }

        return (new ApiRouteResource($route))->response()->setStatusCode(201);
    }

    public function show(int $route): JsonResponse
    {
        $model = $this->service->findRoute($route);
        $model->load('parameters');

        return (new ApiRouteResource($model))->response();
    }

    public function update(UpdateRouteRequest $request, int $route): JsonResponse
    {
        $model = $this->service->findRoute($route);

        try {
            $updated = $this->service->updateRoute($model, $this->routeData($request->validated()));
        } catch (RuntimeException $e) {
            return $this->failure($e);
        }

        return (new ApiRouteResource($updated))->response();
    }

    /**
     * Un-group a `{config}` envelope (the FE's canonical config JSON) into the
     * flat create/update payload via the codec; otherwise pass the flat
     * validated fields straight through (back-compat: CLI / legacy callers).
     *
     * @param  array<string,mixed>  $validated
     * @return array<string,mixed>
     */
    private function routeData(array $validated): array
    {
        if (isset($validated['config']) && is_array($validated['config'])) {
            return RouteConfig::applyToRoute(RouteConfigSchema::sanitize($validated['config']) ?? $validated['config']);
        }

        return $validated;
    }

    /**
     * "Testa" — dry-run a (possibly unsaved) config against its endpoint and
     * classify the live response. Works in create mode: no persisted route. A
     * failed/non-JSON call is a valid outcome (HTTP 200, ok:false, R14).
     */
    public function testConfig(TestConfigRequest $request, int $connector): JsonResponse
    {
        $connectorModel = $this->service->findConnector($connector);
        $out = $this->service->testConfig($connectorModel, $request->config(), $request->exampleArgs());
        /** @var TestResult $result */
        $result = $out['test'];

        return response()->json([
            'test' => $this->testPayload($result),
            'endpoint_type' => $out['endpoint_type'],
            'items_path' => $out['items_path'],
            'detected_pagination' => $out['detected_pagination'],
            'item_count' => $out['item_count'],
        ]);
    }

    /**
     * "Configura con AI" — the single AI pass over the config JSON. Returns the
     * produced config + its final dry-run ("test finale") + the source (openapi /
     * response / none). Does NOT persist; the operator reviews + saves. An
     * OpenAPI fetch/parse/SSRF failure surfaces as 422 (R14).
     */
    public function produceConfig(ProduceConfigRequest $request, int $connector): JsonResponse
    {
        $connectorModel = $this->service->findConnector($connector);

        try {
            $out = $this->service->produceConfig(
                $connectorModel,
                $request->config(),
                $request->exampleArgs(),
                $request->openApiUrl(),
            );
        } catch (RuntimeException $e) {
            return $this->failure($e);
        }

        /** @var TestResult $final */
        $final = $out['final_test'];

        return response()->json([
            'config' => $out['config'],
            'final_test' => $this->testPayload($final),
            'source' => $out['source'],
        ]);
    }

    public function destroy(int $route): JsonResponse
    {
        $model = $this->service->findRoute($route);
        $this->service->deleteRoute($model);

        return response()->json(['deleted' => true]);
    }

    /**
     * Ad-hoc "playground" probe — fire a FREE, unauthenticated, NON-persisted
     * live call ({method, url, headers, query, body}) and return the classified
     * outcome. Like {@see test()} a failed/non-JSON call is a valid display
     * outcome (HTTP 200, ok:false, R14); only a malformed request 422s. No route
     * or connector is created.
     */
    public function probe(ProbeRequest $request): JsonResponse
    {
        $result = $this->service->probe(
            $request->httpMethod(),
            $request->targetUrl(),
            $request->headerMap(),
            $request->queryParams(),
            $request->jsonBody(),
        );

        return response()->json($this->probePayload($result));
    }

    public function test(TestRouteRequest $request, int $route): JsonResponse
    {
        $model = $this->service->findRoute($route);
        $outcome = $this->service->testRoute($model, $request->exampleArgs());
        /** @var TestResult $result */
        $result = $outcome['result'];
        $tested = $outcome['route'];

        // A failed/non-JSON test is a valid display outcome (R14): HTTP 200 with
        // ok:false. Only a missing route (handled above) is a real error code.
        return response()->json([
            'test' => $this->testPayload($result),
            'tool_definition' => $tested->tool_definition,
            'input_schema' => $tested->input_schema,
            'output_schema' => $tested->output_schema,
            'endpoint_type' => $tested->endpoint_type->value,
            'items_path' => $tested->items_path,
            'item_schema' => $this->itemSchema($tested),
        ]);
    }

    /**
     * The JSON schema of a single LIST item, extracted from the inferred
     * output_schema at `items_path` — the shape the relation field-picker maps
     * from. Null for non-list routes or when the schema can't be walked.
     *
     * @return array<string,mixed>|null
     */
    private function itemSchema(ApiRoute $route): ?array
    {
        if ($route->endpoint_type !== EndpointType::List) {
            return null;
        }

        $node = $route->output_schema;
        if (! is_array($node)) {
            return null;
        }

        // Walk the envelope dot-path (e.g. 'data' or 'result.orders'); '' or null
        // means the whole body IS the item array (top-level list).
        $path = (string) ($route->items_path ?? '');
        if ($path !== '') {
            foreach (explode('.', $path) as $segment) {
                $node = $node['properties'][$segment] ?? null;
                if (! is_array($node)) {
                    return null;
                }
            }
        }

        return is_array($node['items'] ?? null) ? $node['items'] : null;
    }

    public function regenerateDescription(int $route): JsonResponse
    {
        $model = $this->service->findRoute($route);

        try {
            $definition = $this->service->regenerateDescription($model);
        } catch (RuntimeException $e) {
            return $this->failure($e);
        }

        return response()->json(['tool_definition' => $definition]);
    }

    public function activate(int $route): JsonResponse
    {
        $model = $this->service->findRoute($route);

        try {
            $activated = $this->service->activateRoute($model);
        } catch (RuntimeException $e) {
            return $this->failure($e);
        }

        return (new ApiRouteResource($activated))->response();
    }

    public function disable(int $route): JsonResponse
    {
        $model = $this->service->findRoute($route);
        $disabled = $this->service->disableRoute($model);

        return (new ApiRouteResource($disabled))->response();
    }

    public function tryTool(TryRouteRequest $request, int $route): JsonResponse
    {
        $model = $this->service->findRoute($route);
        $result = $this->service->tryRoute($model, $request->arguments());

        return response()->json(['result' => $result]);
    }

    /**
     * Workbench "Analisi" — fire the route and return a deterministically
     * REDUCED structure (spec item 3) so the whole shape reads start-to-end,
     * plus reduction `notes` and (P2) an AI narration. Non-JSON/failed call is a
     * valid 200 display outcome (R14) with `reduced=null`.
     */
    public function analyze(TestRouteRequest $request, int $route): JsonResponse
    {
        $model = $this->service->findRoute($route);
        $outcome = $this->service->analyzeRoute($model, $request->exampleArgs());
        /** @var TestResult $result */
        $result = $outcome['result'];

        return response()->json([
            'test' => $this->testPayload($result),
            'reduced' => $this->trimBody($outcome['reduced']),
            'notes' => $outcome['notes'],
            'analysis' => $outcome['analysis'],
        ]);
    }

    /**
     * Workbench "Paginazione" — guess the pagination scheme (heuristic + AI
     * fallback). Non-persisting; the operator confirms/edits then saves via PATCH.
     */
    public function detectPagination(TestRouteRequest $request, int $route): JsonResponse
    {
        $model = $this->service->findRoute($route);
        $out = $this->service->detectPagination($model, $request->exampleArgs());

        return response()->json(['config' => $out['config'], 'source' => $out['source']]);
    }

    /**
     * Workbench "Paginazione" — fire two pages with the given config and report
     * whether page 2 advances (item counts + verdict). Non-persisting.
     */
    public function testPagination(TestPaginationRequest $request, int $route): JsonResponse
    {
        $model = $this->service->findRoute($route);

        return response()->json(
            $this->service->testPagination($model, $request->pagination(), $request->exampleArgs()),
        );
    }

    /**
     * Workbench "Cerca" — fire the route with the operator's search parameters
     * and return the raw response (item 6). Non-persisting; 200 even on failure.
     */
    public function testSearch(TestRouteRequest $request, int $route): JsonResponse
    {
        $model = $this->service->findRoute($route);
        $result = $this->service->testSearch($model, $request->exampleArgs());

        return response()->json(['test' => $this->testPayload($result)]);
    }

    /**
     * "Configura con AI" — propose the full route configuration (endpoint type,
     * items_path, pagination, tool name/description, parameters) from a test
     * call. Non-persisting: the operator applies it via PATCH. `suggestion` is
     * null when the call returned no JSON (R14).
     */
    public function aiConfigure(TestRouteRequest $request, int $route): JsonResponse
    {
        $model = $this->service->findRoute($route);
        $out = $this->service->autoConfigure($model, $request->exampleArgs());
        /** @var TestResult $result */
        $result = $out['result'];

        return response()->json([
            'test' => $this->testPayload($result),
            'suggestion' => $out['suggestion'],
        ]);
    }

    /**
     * "Configura con AI" ONE-SHOT — detect + apply + final test in one call.
     * Returns what was applied, the final test outcome (the route is now
     * `tested`), and the pagination verdict when a scheme was configured.
     * `applied` is null when the call returned no JSON (R14).
     */
    public function aiConfigureApply(AiConfigureApplyRequest $request, int $route): JsonResponse
    {
        $model = $this->service->findRoute($route);

        try {
            $out = $this->service->applyAiConfiguration($model, $request->exampleArgs(), $request->openApiUrl());
        } catch (RuntimeException $e) {
            return $this->failure($e); // OpenAPI fetch/parse/SSRF failure → 422
        }

        /** @var TestResult $final */
        $final = $out['final_test'];

        return response()->json([
            'applied' => $out['applied'],
            'final_test' => $this->testPayload($final),
            'pagination_test' => $out['pagination_test'],
            'source' => $out['source'],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function probePayload(TestResult $result): array
    {
        return $this->testPayload($result) + ['duration_ms' => $result->durationMs];
    }

    /**
     * @return array<string,mixed>
     */
    private function testPayload(TestResult $result): array
    {
        return [
            'ok' => $result->ok,
            'status' => $result->status,
            'status_label' => $result->statusLabel(),
            'is_json' => $result->isJson,
            'error' => $result->error,
            'headers' => $result->headers,
            'body' => $this->trimBody($result->body),
        ];
    }

    /**
     * Keep the displayed body bounded so a large response cannot blow up the
     * admin payload (R14 — surface, don't dump). Lists are capped to the first
     * 20 elements; raw strings are byte-capped.
     */
    private function trimBody(mixed $body): mixed
    {
        if (is_string($body)) {
            return mb_strcut($body, 0, 8192, 'UTF-8');
        }

        if (is_array($body) && array_is_list($body) && count($body) > 20) {
            return array_slice($body, 0, 20);
        }

        return $body;
    }

    private function failure(RuntimeException $e): JsonResponse
    {
        $status = $e->getCode() === 422 ? 422 : 500;

        return response()->json(['message' => $e->getMessage()], $status);
    }
}
