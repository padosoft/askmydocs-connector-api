<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Padosoft\AskMyDocsConnectorApi\Support\HttpMethod;
use Padosoft\AskMyDocsConnectorApi\Support\RouteMode;
use Padosoft\AskMyDocsConnectorApi\Support\RouteStatus;
use Padosoft\AskMyDocsConnectorBase\Models\Concerns\BelongsToTenant;

/**
 * API route (Rotta) — one endpoint = one LLM Tool (spec §4 / §6).
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $api_connector_id
 * @property string $project_key
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property HttpMethod $http_method
 * @property string $url
 * @property int|null $auth_profile_id
 * @property array<string,mixed>|null $input_schema
 * @property array<string,mixed>|null $output_schema
 * @property array<string,mixed>|null $param_mapping
 * @property array<string,mixed>|null $tool_definition
 * @property array<string,mixed>|null $output_transform
 * @property RouteMode $mode
 * @property RouteStatus $status
 * @property int|null $timeout_ms
 * @property int|null $cache_ttl_s
 * @property int|null $rate_limit
 * @property Carbon|null $last_test_at
 * @property string|null $last_test_status
 * @property array<string,mixed>|null $last_test_payload
 * @property-read Carbon|null $created_at
 * @property-read Carbon|null $updated_at
 * @property-read ApiConnector|null $connector
 * @property-read Collection<int,ApiRouteParameter> $parameters
 * @property-read Collection<int,ApiToolCallLog> $callLogs
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> forTenant(string $tenantId)
 * @method static \Illuminate\Database\Eloquent\Builder<static> exposesTool()
 */
class ApiRoute extends Model
{
    use BelongsToTenant;

    protected $table = 'api_routes';

    protected $fillable = [
        'tenant_id',
        'api_connector_id',
        'project_key',
        'name',
        'slug',
        'description',
        'http_method',
        'url',
        'auth_profile_id',
        'input_schema',
        'output_schema',
        'param_mapping',
        'tool_definition',
        'output_transform',
        'mode',
        'status',
        'timeout_ms',
        'cache_ttl_s',
        'rate_limit',
        'last_test_at',
        'last_test_status',
        'last_test_payload',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'http_method' => HttpMethod::class,
        'mode' => RouteMode::class,
        'status' => RouteStatus::class,
        'input_schema' => 'array',
        'output_schema' => 'array',
        'param_mapping' => 'array',
        'tool_definition' => 'array',
        'output_transform' => 'array',
        'last_test_payload' => 'array',
        'last_test_at' => 'datetime',
        'auth_profile_id' => 'integer',
        'timeout_ms' => 'integer',
        'cache_ttl_s' => 'integer',
        'rate_limit' => 'integer',
    ];

    /** @return BelongsTo<ApiConnector, $this> */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(ApiConnector::class, 'api_connector_id');
    }

    /** @return HasMany<ApiRouteParameter, $this> */
    public function parameters(): HasMany
    {
        $relation = $this->hasMany(ApiRouteParameter::class);
        $relation->orderBy('sort_order');

        return $relation;
    }

    /** @return HasMany<ApiToolCallLog, $this> */
    public function callLogs(): HasMany
    {
        return $this->hasMany(ApiToolCallLog::class);
    }

    /**
     * Routes that contribute a live tool to the chat loop: active + tool/both.
     *
     * @param  Builder<ApiRoute>  $query
     * @return Builder<ApiRoute>
     */
    public function scopeExposesTool(Builder $query): Builder
    {
        $query
            ->where('status', RouteStatus::Active->value)
            ->whereIn('mode', [RouteMode::Tool->value, RouteMode::Both->value]);

        return $query;
    }

    /**
     * The auth profile that applies to this route: its own override, else the
     * connector default. Caller is responsible for tenant scoping (R30).
     */
    public function effectiveAuthProfile(): ?ApiAuthProfile
    {
        if ($this->auth_profile_id !== null) {
            return ApiAuthProfile::forTenant($this->tenant_id)
                ->whereKey($this->auth_profile_id)
                ->first();
        }

        return $this->connector?->defaultAuthProfile();
    }
}
