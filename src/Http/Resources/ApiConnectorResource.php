<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;

/**
 * Public shape of a connector. The `routes` collection is a compact summary
 * (id, name, slug, status, mode, http_method, last_test_status) suitable for
 * both the list (`index`) and the detail (`show`) views; `auth_profiles` is
 * rendered secret-hidden via {@see ApiAuthProfileResource} only when loaded.
 *
 * @mixin ApiConnector
 */
final class ApiConnectorResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_key' => $this->project_key,
            'name' => $this->name,
            'description' => $this->description,
            'base_url' => $this->base_url,
            'default_auth_profile_id' => $this->default_auth_profile_id,
            'headers' => is_array($this->headers) ? $this->headers : [],
            'is_active' => $this->is_active,
            'routes' => $this->when(
                $this->relationLoaded('routes'),
                fn (): array => $this->routes->map(
                    fn (ApiRoute $route): array => $this->routeSummary($route)
                )->all(),
            ),
            'auth_profiles' => ApiAuthProfileResource::collection(
                $this->whenLoaded('authProfiles')
            ),
            'relations' => ApiRouteRelationResource::collection(
                $this->whenLoaded('relations')
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Compact route row for the connector listing/detail (spec §5 list view).
     *
     * @return array<string,mixed>
     */
    private function routeSummary(ApiRoute $route): array
    {
        return [
            'id' => $route->id,
            'name' => $route->name,
            'slug' => $route->slug,
            'status' => $route->status->value,
            'mode' => $route->mode->value,
            'endpoint_type' => $route->endpoint_type->value,
            'http_method' => $route->http_method->value,
            'last_test_status' => $route->last_test_status,
        ];
    }
}
