<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Padosoft\AskMyDocsConnectorApi\Support\ParamLocation;
use Padosoft\AskMyDocsConnectorApi\Support\ParamSource;
use Padosoft\AskMyDocsConnectorApi\Support\ParamType;
use Padosoft\AskMyDocsConnectorBase\Models\Concerns\BelongsToTenant;

/**
 * Single route parameter (spec §4.2).
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $api_route_id
 * @property string $name
 * @property ParamLocation $location
 * @property ParamSource $source
 * @property ParamType $type
 * @property bool $required
 * @property string|null $value
 * @property string|null $secret_ref
 * @property string|null $description
 * @property int $sort_order
 */
class ApiRouteParameter extends Model
{
    use BelongsToTenant;

    protected $table = 'api_route_parameters';

    protected $fillable = [
        'tenant_id',
        'api_route_id',
        'name',
        'location',
        'source',
        'type',
        'required',
        'value',
        'secret_ref',
        'description',
        'sort_order',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'location' => ParamLocation::class,
        'source' => ParamSource::class,
        'type' => ParamType::class,
        'required' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** @return BelongsTo<ApiRoute, $this> */
    public function route(): BelongsTo
    {
        return $this->belongsTo(ApiRoute::class, 'api_route_id');
    }

    /** Only `source = llm` params are exposed to the model. */
    public function isExposedToLlm(): bool
    {
        return $this->source === ParamSource::Llm;
    }
}
