<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Padosoft\AskMyDocsConnectorApi\Support\HttpMethod;
use Padosoft\AskMyDocsConnectorBase\Models\Concerns\BelongsToTenant;

/**
 * API connector container (spec §3.1). Groups Rotte + shared auth / base_url /
 * headers. Tenant-aware (R31): `BelongsToTenant` auto-fills `tenant_id`.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string|null $project_key
 * @property string $name
 * @property string|null $description
 * @property string|null $base_url
 * @property int|null $default_auth_profile_id
 * @property array<string,string>|null $headers
 * @property bool $is_active
 * @property-read Carbon|null $created_at
 * @property-read Carbon|null $updated_at
 * @property-read Collection<int,ApiRoute> $routes
 * @property-read Collection<int,ApiAuthProfile> $authProfiles
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> forTenant(string $tenantId)
 */
class ApiConnector extends Model
{
    use BelongsToTenant;

    protected $table = 'api_connectors';

    protected $fillable = [
        'tenant_id',
        'project_key',
        'name',
        'description',
        'base_url',
        'default_auth_profile_id',
        'headers',
        'is_active',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'headers' => 'array',
        'is_active' => 'boolean',
        'default_auth_profile_id' => 'integer',
    ];

    /** @return HasMany<ApiRoute, $this> */
    public function routes(): HasMany
    {
        return $this->hasMany(ApiRoute::class);
    }

    /** @return HasMany<ApiAuthProfile, $this> */
    public function authProfiles(): HasMany
    {
        return $this->hasMany(ApiAuthProfile::class);
    }

    public function defaultAuthProfile(): ?ApiAuthProfile
    {
        if ($this->default_auth_profile_id === null) {
            return null;
        }

        return $this->authProfiles()
            ->whereKey($this->default_auth_profile_id)
            ->first();
    }

    /** Effective project scope used by routes ('' when unset). */
    public function projectScope(): string
    {
        return (string) ($this->project_key ?? '');
    }

    /**
     * Static headers shared by every route (defaults that a route may extend).
     *
     * @return array<string,string>
     */
    public function sharedHeaders(): array
    {
        return is_array($this->headers) ? $this->headers : [];
    }

    /** Default HTTP method hint for the UI when adding a new route. */
    public function defaultMethod(): HttpMethod
    {
        return HttpMethod::GET;
    }
}
