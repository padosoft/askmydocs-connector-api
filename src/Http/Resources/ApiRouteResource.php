<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;

/**
 * Public shape of a Rotta (route). Includes the generated artifacts
 * (input/output schema, tool_definition) + the last test outcome so the editor
 * can render the full state. Parameters are rendered via
 * {@see ApiRouteParameterResource} when the relation is loaded.
 *
 * @mixin ApiRoute
 */
final class ApiRouteResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'api_connector_id' => $this->api_connector_id,
            'project_key' => $this->project_key,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'http_method' => $this->http_method->value,
            'url' => $this->url,
            'auth_profile_id' => $this->auth_profile_id,
            'mode' => $this->mode->value,
            'status' => $this->status->value,
            'timeout_ms' => $this->timeout_ms,
            'cache_ttl_s' => $this->cache_ttl_s,
            'rate_limit' => $this->rate_limit,
            'input_schema' => $this->input_schema,
            'output_schema' => $this->output_schema,
            'param_mapping' => $this->param_mapping,
            'tool_definition' => $this->tool_definition,
            'output_transform' => $this->output_transform,
            'last_test_at' => $this->last_test_at?->toIso8601String(),
            'last_test_status' => $this->last_test_status,
            'last_test_payload' => $this->last_test_payload,
            'parameters' => ApiRouteParameterResource::collection(
                $this->whenLoaded('parameters')
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
