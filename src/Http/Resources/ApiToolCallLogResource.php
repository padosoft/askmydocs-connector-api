<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Padosoft\AskMyDocsConnectorApi\Models\ApiToolCallLog;

/**
 * Public shape of a runtime tool-call log row. The executor already stores
 * request_params sanitised (secrets redacted) and the excerpt truncated, so the
 * row is safe to render as-is.
 *
 * @mixin ApiToolCallLog
 */
final class ApiToolCallLogResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'api_route_id' => $this->api_route_id,
            'request_params' => $this->request_params,
            'response_status' => $this->response_status,
            'response_excerpt' => $this->response_excerpt,
            'latency_ms' => $this->latency_ms,
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
