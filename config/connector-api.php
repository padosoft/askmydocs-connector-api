<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | SSRF / URL safety
    |--------------------------------------------------------------------------
    | UrlGuard validates every configured URL both at "Test connessione" time
    | and at runtime before each tool call. Blocks private / loopback /
    | link-local ranges + the cloud metadata endpoint (169.254.169.254) by
    | default. The domain allowlist is opt-in: when `allowlist` is non-empty
    | only those hosts (and their subdomains) are permitted.
    */
    'ssrf' => [
        'enabled' => (bool) env('API_CONNECTOR_SSRF_ENABLED', true),
        'https_only' => (bool) env('API_CONNECTOR_HTTPS_ONLY', true),
        // Resolve hostnames and check every A/AAAA address (DNS-rebinding guard).
        'resolve_dns' => (bool) env('API_CONNECTOR_SSRF_RESOLVE_DNS', true),
        // list<string> of allowed host suffixes, e.g. ['api.clientex.com'].
        // Empty = allow any public host (still blocks private ranges).
        'allowlist' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('API_CONNECTOR_DOMAIN_ALLOWLIST', '')),
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Output limits
    |--------------------------------------------------------------------------
    | Maximum size (in bytes) of the JSON tool_result returned to the LLM.
    | Larger payloads are truncated with an explanatory note so the context
    | does not blow up and to limit mass exfiltration.
    */
    'output' => [
        'max_bytes' => (int) env('API_CONNECTOR_OUTPUT_MAX_BYTES', 16384),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tool registry
    |--------------------------------------------------------------------------
    | Hard cap on the number of API tools injected into a single conversation
    | so the LLM's tool-routing does not degrade. `0` = no cap.
    */
    'tools' => [
        'max_per_conversation' => (int) env('API_CONNECTOR_MAX_TOOLS_PER_CONVERSATION', 16),
    ],

    /*
    |--------------------------------------------------------------------------
    | Operational defaults (overridable per Rotta)
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'timeout_ms' => (int) env('API_CONNECTOR_DEFAULT_TIMEOUT_MS', 10000),
        // Retry only on transient failures (5xx / timeout), never on 4xx.
        'retry_times' => (int) env('API_CONNECTOR_RETRY_TIMES', 2),
        'retry_backoff_ms' => (int) env('API_CONNECTOR_RETRY_BACKOFF_MS', 250),
        'cache_ttl_s' => (int) env('API_CONNECTOR_CACHE_TTL_S', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | LLM-assisted tool description
    |--------------------------------------------------------------------------
    | When enabled the host's bound ToolDescriptionAssistant generates the
    | tool name/description from the test call; otherwise a field-derived draft
    | is used. The user can always edit the result.
    */
    'llm_assist' => [
        'enabled' => (bool) env('API_CONNECTOR_LLM_ASSIST', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin HTTP routes
    |--------------------------------------------------------------------------
    | The package ships admin routes under this prefix. The HOST application
    | MUST override `middleware` with its authenticated admin stack
    | (auth:sanctum + tenant scoping + an RBAC gate) — R32. The default `api`
    | middleware leaves them UNAUTHENTICATED and is for standalone dev only.
    */
    'routes' => [
        'enabled' => (bool) env('API_CONNECTOR_ROUTES_ENABLED', true),
        'prefix' => env('API_CONNECTOR_ROUTES_PREFIX', 'api/admin/api-connectors'),
        'middleware' => ['api'],
    ],
];
