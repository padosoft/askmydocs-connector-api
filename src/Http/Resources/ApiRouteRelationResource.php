<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteRelation;

/**
 * Public shape of a List → Detail relation (spec Obj 3). Carries the field_map
 * plus a compact `{id, slug}` of each side when the routes are eager-loaded, so
 * the editor can render the link without a second fetch.
 *
 * @mixin ApiRouteRelation
 */
final class ApiRouteRelationResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'api_connector_id' => $this->api_connector_id,
            'list_route_id' => $this->list_route_id,
            'detail_route_id' => $this->detail_route_id,
            'name' => $this->name,
            'description' => $this->description,
            'field_map' => $this->field_map,
            'sort_order' => $this->sort_order,
            'list_route' => $this->relationLoaded('listRoute')
                ? $this->routeStub($this->listRoute)
                : new MissingValue,
            'detail_route' => $this->relationLoaded('detailRoute')
                ? $this->routeStub($this->detailRoute)
                : new MissingValue,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The compact side of a relation. Reads id + name + slug + endpoint_type, so
     * any eager load that feeds this stub MUST select all four columns
     * (endpoint_type is NOT NULL, but a partial `:id,slug` select would leave the
     * enum unset and 500 here). See ConnectorAdminService::listConnectors().
     *
     * @return array<string,mixed>
     */
    private function routeStub(ApiRoute $route): array
    {
        return [
            'id' => $route->id,
            'name' => $route->name,
            'slug' => $route->slug,
            'endpoint_type' => $route->endpoint_type->value,
        ];
    }
}
