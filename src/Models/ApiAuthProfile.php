<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Padosoft\AskMyDocsConnectorApi\Support\AuthType;
use Padosoft\AskMyDocsConnectorBase\Models\Concerns\BelongsToTenant;

/**
 * Authentication profile (spec §4.3). `credentials` is encrypted at rest
 * (`encrypted:array`) and `$hidden` — the values never reach the LLM, the API
 * responses or the logs. R21: the secret only ever leaves this model inside the
 * server-side executor.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $api_connector_id
 * @property AuthType $type
 * @property array<string,mixed>|null $credentials decrypted on read
 * @property array<string,mixed>|null $config
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> forTenant(string $tenantId)
 */
class ApiAuthProfile extends Model
{
    use BelongsToTenant;

    protected $table = 'api_auth_profiles';

    protected $fillable = [
        'tenant_id',
        'api_connector_id',
        'type',
        'credentials',
        'config',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'type' => AuthType::class,
        'credentials' => 'encrypted:array',
        'config' => 'array',
    ];

    /**
     * Secrets must never be serialized to JSON responses or logs.
     *
     * @var list<string>
     */
    protected $hidden = ['credentials'];

    /** @return BelongsTo<ApiConnector, $this> */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(ApiConnector::class, 'api_connector_id');
    }

    /** Decrypted credential value by key, or null. Server-side use only. */
    public function credential(string $key): ?string
    {
        $credentials = $this->credentials;
        if (! is_array($credentials)) {
            return null;
        }

        $value = $credentials[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}
