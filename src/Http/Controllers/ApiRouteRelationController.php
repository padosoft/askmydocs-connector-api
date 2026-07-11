<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\DrillRelationRequest;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\StoreRouteRelationRequest;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\UpdateRouteRelationRequest;
use Padosoft\AskMyDocsConnectorApi\Http\Resources\ApiRouteRelationResource;
use Padosoft\AskMyDocsConnectorApi\Services\ConnectorAdminService;
use Padosoft\AskMyDocsConnectorApi\Support\TestResult;
use RuntimeException;

/**
 * Thin admin controller for List → Detail relations (spec Obj 3). request →
 * service → resource; tenant scoping (R30) + the semantic validation matrix
 * (422) live in {@see ConnectorAdminService}. A guessed cross-tenant id 404s
 * (tenant-scoped loaders, no implicit binding).
 *
 * `drill` mirrors the route `test` posture (R14): a failed detail call is a
 * valid HTTP 200 outcome; only a mapping that cannot even be built (missing
 * field / no item) is a 422.
 */
final class ApiRouteRelationController extends Controller
{
    public function __construct(private readonly ConnectorAdminService $service) {}

    public function index(int $connector): JsonResponse
    {
        $connectorModel = $this->service->findConnector($connector);

        return ApiRouteRelationResource::collection(
            $this->service->listRelations($connectorModel)
        )->response();
    }

    public function store(StoreRouteRelationRequest $request, int $connector): JsonResponse
    {
        $connectorModel = $this->service->findConnector($connector);

        try {
            $relation = $this->service->createRelation($connectorModel, $request->validated());
        } catch (RuntimeException $e) {
            return $this->failure($e);
        }

        return (new ApiRouteRelationResource($relation))->response()->setStatusCode(201);
    }

    public function show(int $relation): JsonResponse
    {
        $model = $this->service->findRelation($relation);
        $model->load(['listRoute', 'detailRoute']);

        return (new ApiRouteRelationResource($model))->response();
    }

    public function update(UpdateRouteRelationRequest $request, int $relation): JsonResponse
    {
        $model = $this->service->findRelation($relation);

        try {
            $updated = $this->service->updateRelation($model, $request->validated());
        } catch (RuntimeException $e) {
            return $this->failure($e);
        }

        return (new ApiRouteRelationResource($updated))->response();
    }

    public function destroy(int $relation): JsonResponse
    {
        $model = $this->service->findRelation($relation);
        $this->service->deleteRelation($model);

        return response()->json(['deleted' => true]);
    }

    public function drill(DrillRelationRequest $request, int $relation): JsonResponse
    {
        $model = $this->service->findRelation($relation);

        try {
            $outcome = $this->service->drillTest($model, $request->listItem(), $request->itemIndex());
        } catch (RuntimeException $e) {
            return $this->failure($e);
        }

        /** @var TestResult $result */
        $result = $outcome['result'];

        return response()->json([
            'arguments' => $outcome['arguments'],
            'result' => [
                'ok' => $result->ok,
                'status' => $result->status,
                'status_label' => $result->statusLabel(),
                'is_json' => $result->isJson,
                'error' => $result->error,
                'headers' => $result->headers,
                'body' => $this->trimBody($result->body),
                'duration_ms' => $result->durationMs,
            ],
        ]);
    }

    /**
     * Bound display body (R14): dryRun bypasses the runtime output cap, so cap
     * here — lists to the first 20 elements, raw strings byte-capped.
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
