<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Padosoft\AskMyDocsConnectorBase\Models\Concerns\BelongsToTenant;

/**
 * Runtime tool-call log (spec §9). Request params are stored already sanitised
 * (secrets redacted) by the executor; the response excerpt is truncated +
 * redacted. Append-only — no `updated_at`.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int|null $conversation_id
 * @property int $api_route_id
 * @property array<string,mixed>|null $request_params
 * @property int|null $response_status
 * @property array<string,mixed>|null $response_excerpt
 * @property int|null $latency_ms
 * @property string|null $error
 * @property Carbon|null $created_at
 */
class ApiToolCallLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'api_tool_call_logs';

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'api_route_id',
        'request_params',
        'response_status',
        'response_excerpt',
        'latency_ms',
        'error',
        'created_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'request_params' => 'array',
        'response_excerpt' => 'array',
        'response_status' => 'integer',
        'latency_ms' => 'integer',
        'conversation_id' => 'integer',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<ApiRoute, $this> */
    public function route(): BelongsTo
    {
        return $this->belongsTo(ApiRoute::class, 'api_route_id');
    }
}
