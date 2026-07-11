<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\StoreConnectorRequest;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\UpdateConnectorRequest;
use Padosoft\AskMyDocsConnectorApi\Http\Resources\ApiConnectorResource;
use Padosoft\AskMyDocsConnectorApi\Services\ConnectorAdminService;
use RuntimeException;

/**
 * Thin admin controller for the connector resource (spec §5). Every handler is
 * request → service → resource; tenant scoping (R30) and persistence live in
 * {@see ConnectorAdminService}. The R28 project_key guard surfaces as a 422.
 */
final class ApiConnectorController extends Controller
{
    public function __construct(private readonly ConnectorAdminService $service) {}

    public function index(): JsonResponse
    {
        $connectors = $this->service->listConnectors();

        return ApiConnectorResource::collection($connectors)->response();
    }

    public function store(StoreConnectorRequest $request): JsonResponse
    {
        $connector = $this->service->createConnector($request->validated());

        return (new ApiConnectorResource($connector))->response()->setStatusCode(201);
    }

    public function show(int $connector): JsonResponse
    {
        $model = $this->service->findConnector($connector);
        $model->load(['routes', 'authProfiles', 'relations.listRoute:id,slug', 'relations.detailRoute:id,slug']);

        return (new ApiConnectorResource($model))->response();
    }

    public function update(UpdateConnectorRequest $request, int $connector): JsonResponse
    {
        $model = $this->service->findConnector($connector);

        try {
            $updated = $this->service->updateConnector($model, $request->validated());
        } catch (RuntimeException $e) {
            return $this->failure($e);
        }

        return (new ApiConnectorResource($updated))->response();
    }

    public function destroy(int $connector): JsonResponse
    {
        $model = $this->service->findConnector($connector);
        $this->service->deleteConnector($model);

        return response()->json(['deleted' => true]);
    }

    private function failure(RuntimeException $e): JsonResponse
    {
        $status = $e->getCode() === 422 ? 422 : 500;

        return response()->json(['message' => $e->getMessage()], $status);
    }
}
