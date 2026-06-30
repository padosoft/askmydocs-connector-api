<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\StoreRouteRequest;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\TestRouteRequest;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\TryRouteRequest;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\UpdateRouteRequest;
use Padosoft\AskMyDocsConnectorApi\Http\Resources\ApiRouteResource;
use Padosoft\AskMyDocsConnectorApi\Services\ConnectorAdminService;
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
            $route = $this->service->createRoute($connectorModel, $request->validated());
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
            $updated = $this->service->updateRoute($model, $request->validated());
        } catch (RuntimeException $e) {
            return $this->failure($e);
        }

        return (new ApiRouteResource($updated))->response();
    }

    public function destroy(int $route): JsonResponse
    {
        $model = $this->service->findRoute($route);
        $this->service->deleteRoute($model);

        return response()->json(['deleted' => true]);
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
        ]);
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
