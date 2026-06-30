<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\StoreAuthProfileRequest;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\UpdateAuthProfileRequest;
use Padosoft\AskMyDocsConnectorApi\Http\Resources\ApiAuthProfileResource;
use Padosoft\AskMyDocsConnectorApi\Services\ConnectorAdminService;

/**
 * Thin admin controller for auth profiles (spec §4.3). Resources NEVER expose
 * the encrypted credentials (R21) — only type + config + a has_credentials flag.
 */
final class ApiAuthProfileController extends Controller
{
    public function __construct(private readonly ConnectorAdminService $service) {}

    public function store(StoreAuthProfileRequest $request, int $connector): JsonResponse
    {
        $connectorModel = $this->service->findConnector($connector);
        $profile = $this->service->createAuthProfile($connectorModel, $request->validated());

        return (new ApiAuthProfileResource($profile))->response()->setStatusCode(201);
    }

    public function update(UpdateAuthProfileRequest $request, int $profile): JsonResponse
    {
        $model = $this->service->findAuthProfile($profile);
        $updated = $this->service->updateAuthProfile($model, $request->validated());

        return (new ApiAuthProfileResource($updated))->response();
    }

    public function destroy(int $profile): JsonResponse
    {
        $model = $this->service->findAuthProfile($profile);
        $this->service->deleteAuthProfile($model);

        return response()->json(['deleted' => true]);
    }
}
