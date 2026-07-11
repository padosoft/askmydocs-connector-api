<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Padosoft\AskMyDocsConnectorBase\Models\Concerns\BelongsToTenant;

/**
 * List → Detail relation (spec Obj 3). Binds a LIST route to a DETAIL route with
 * a `field_map` that maps a single list item's fields onto the detail route's
 * parameters. Powers both the admin drill-test and the Fase-3 tool-description
 * annotations that let the LLM chain the list tool into the detail tool.
 *
 * Tenant-aware (R30/R31) via BelongsToTenant. `field_map` is an ordered list of
 * `{from: string, to_param: string, to_location?: string}`.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $api_connector_id
 * @property int $list_route_id
 * @property int $detail_route_id
 * @property string|null $name
 * @property string|null $description
 * @property list<array{from: string, to_param: string, to_location?: string}> $field_map
 * @property int $sort_order
 * @property-read Carbon|null $created_at
 * @property-read Carbon|null $updated_at
 * @property-read ApiConnector $connector
 * @property-read ApiRoute $listRoute
 * @property-read ApiRoute $detailRoute
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> forTenant(string $tenantId)
 */
class ApiRouteRelation extends Model
{
    use BelongsToTenant;

    protected $table = 'api_route_relations';

    protected $fillable = [
        'tenant_id',
        'api_connector_id',
        'list_route_id',
        'detail_route_id',
        'name',
        'description',
        'field_map',
        'sort_order',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'api_connector_id' => 'integer',
        'list_route_id' => 'integer',
        'detail_route_id' => 'integer',
        'field_map' => 'array',
        'sort_order' => 'integer',
    ];

    /** @return BelongsTo<ApiConnector, $this> */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(ApiConnector::class, 'api_connector_id');
    }

    /** @return BelongsTo<ApiRoute, $this> */
    public function listRoute(): BelongsTo
    {
        return $this->belongsTo(ApiRoute::class, 'list_route_id');
    }

    /** @return BelongsTo<ApiRoute, $this> */
    public function detailRoute(): BelongsTo
    {
        return $this->belongsTo(ApiRoute::class, 'detail_route_id');
    }
}
