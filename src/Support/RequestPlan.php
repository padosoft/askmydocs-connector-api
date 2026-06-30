<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Support;

/**
 * A fully-resolved outbound request (path params substituted, query/header/body
 * assembled). `loggableParams` is the SANITISED subset (llm + fixed only) safe
 * to persist in api_tool_call_logs — secret param values and auth material are
 * deliberately excluded.
 */
final class RequestPlan
{
    /**
     * @param  array<string,mixed>  $query
     * @param  array<string,string>  $headers
     * @param  array<string,mixed>|null  $body
     * @param  array<string,mixed>  $loggableParams
     */
    public function __construct(
        public readonly HttpMethod $method,
        public readonly string $url,
        public readonly array $query = [],
        public readonly array $headers = [],
        public readonly ?array $body = null,
        public readonly array $loggableParams = [],
    ) {}
}
