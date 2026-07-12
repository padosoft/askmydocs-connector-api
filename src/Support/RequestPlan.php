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

    /**
     * A copy with ad-hoc query params merged in (pagination page/cursor, search
     * overrides). Diagnostic-only: these bypass the declared-param binding, so
     * they never touch `loggableParams`. Merged values win over the declared query.
     *
     * @param  array<string,mixed>  $extra
     */
    public function withMergedQuery(array $extra): self
    {
        if ($extra === []) {
            return $this;
        }

        return new self(
            $this->method,
            $this->url,
            array_merge($this->query, $extra),
            $this->headers,
            $this->body,
            $this->loggableParams,
        );
    }
}
