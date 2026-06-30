<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Support;

enum ParamSource: string
{
    /** Value decided by the LLM at runtime — enters the tool input schema. */
    case Llm = 'llm';
    /** Constant value set in config — hidden from the LLM. */
    case Fixed = 'fixed';
    /** Credential pulled from the auth profile — NEVER exposed. */
    case Secret = 'secret';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
