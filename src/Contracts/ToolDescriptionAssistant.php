<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Contracts;

/**
 * Optional LLM-assist for generating a tool name + description from a test call
 * (spec §5.1 step 6). The host binds an implementation backed by its AI manager;
 * the package ships a no-op default so it works without the host (field-derived
 * drafts are used instead).
 */
interface ToolDescriptionAssistant
{
    /**
     * Suggest a tool name (snake_case slug) + description from the route context.
     *
     * @param  array<string,mixed>  $context  {method, url, params, response_sample}
     * @return array{name?: string, description?: string}|null  null when unavailable
     */
    public function suggest(array $context): ?array;
}
