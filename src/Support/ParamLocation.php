<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Support;

enum ParamLocation: string
{
    case Path = 'path';
    case Query = 'query';
    case Header = 'header';
    case Body = 'body';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $l): string => $l->value, self::cases());
    }
}
