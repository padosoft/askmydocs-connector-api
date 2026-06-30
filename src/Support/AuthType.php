<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Support;

enum AuthType: string
{
    case None = 'none';
    case ApiKey = 'api_key';
    case Bearer = 'bearer';
    case Basic = 'basic';
    case Custom = 'custom';
    case OAuth2ClientCredentials = 'oauth2_cc';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
