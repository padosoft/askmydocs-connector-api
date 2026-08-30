<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Contracts;

/**
 * Default no-op analyst — returns null so the workbench shows only the
 * deterministic reduced structure. The host rebinds this to an AI-backed impl.
 */
final class NullResponseAnalyst implements ResponseAnalyst
{
    public function produceConfig(array $context): ?array
    {
        return null;
    }
}
