<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Contracts;

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
}
