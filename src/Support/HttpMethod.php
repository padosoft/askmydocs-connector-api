<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Support;

enum HttpMethod: string
{
    case GET = 'GET';
    case POST = 'POST';
    case PUT = 'PUT';
    case PATCH = 'PATCH';
    case DELETE = 'DELETE';

    /** Whether this method conventionally carries a request body. */
    public function allowsBody(): bool
    {
        return in_array($this, [self::POST, self::PUT, self::PATCH], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $m): string => $m->value, self::cases());
    }
}
