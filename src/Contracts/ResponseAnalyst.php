<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Contracts;

use Padosoft\AskMyDocsConnectorApi\Support\PaginationDetector;
use Padosoft\AskMyDocsConnectorApi\Support\StructureReducer;

/**
 * Optional LLM narration of a route's response STRUCTURE (spec item 3 AI half).
 * It runs on the already-REDUCED body (see {@see StructureReducer})
 * so the prompt stays small and the whole shape is visible. The host binds an
 * implementation backed by its AI manager; the package ships a no-op default so
 * the workbench works without the host (the deterministic reduced view is always
 * shown regardless).
 */
interface ResponseAnalyst
{
    /**
     * @param  array{method: string, url: string, reduced: mixed, notes: list<array<string,mixed>>}  $context
     * @return string|null a short human-readable structural analysis, null when unavailable
     */
    public function analyze(array $context): ?string;

    /**
     * Best-effort structured guess of the pagination scheme (spec item 4), used
     * as the FALLBACK when the deterministic {@see PaginationDetector}
     * can't decide. Returns a config array — `{type: page|cursor, page_param? /
     * cursor_param?, next_cursor_path? / next_url_path?, size_param?, items_path?}`
     * — or null when unavailable / unclear.
     *
     * @param  array{method: string, url: string, reduced: mixed}  $context
     * @return array<string,mixed>|null
     */
    public function detectPagination(array $context): ?array;

    /**
     * Best-effort FULL configuration suggestion for turning the endpoint into a
     * tool ("Configura con AI") — a concise tool name + description, the likely
     * request parameters, and (if evident) the pagination scheme. The operator
     * reviews and applies it. Null when unavailable.
     *
     * @deprecated superseded by {@see self::produceConfig()} — emits the whole
     *             canonical config JSON in one call instead of a partial suggestion.
     *
     * @param  array{method: string, url: string, reduced: mixed}  $context
     * @return array{tool_name?: string, tool_description?: string, parameters?: list<array<string,mixed>>, pagination?: array<string,mixed>}|null
     */
    public function suggestConfiguration(array $context): ?array;

    /**
     * Produce the ENTIRE canonical config JSON for a route in a SINGLE call.
     *
     * The model is given how the endpoint is called (method + url + the args
     * used), a truncated live response sample (+reduction notes), the target
     * config JSON Schema, and a deterministic seed (endpoint_type / items_path /
     * pagination the host already computed exactly). It returns a config JSON —
     * grouped identity·request·response·options — that the caller sanitizes and
     * feeds straight to the codec. Null when unavailable (Null analyst / failed
     * parse); the caller then falls back to the deterministic seed.
     *
     * @param  array{
     *     method: string,
     *     url: string,
     *     example_args: array<string,mixed>,
     *     reduced: mixed,
     *     notes: list<array<string,mixed>>,
     *     schema: array<string,mixed>,
     *     seed: array<string,mixed>,
     *     current?: array<string,mixed>
     * }  $context
     * @return array<string,mixed>|null a config JSON (unsanitized), or null
     */
    public function produceConfig(array $context): ?array;
}
