<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Contracts;

/**
 * Default no-op assistant — returns null so the generator falls back to
 * field-derived drafts. The host rebinds this to an AI-backed implementation.
 */
final class NullToolDescriptionAssistant implements ToolDescriptionAssistant
{
    public function suggest(array $context): ?array
    {
        return null;
    }
}
