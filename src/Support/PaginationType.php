<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Support;

enum PaginationType: string
{
    /** Page-number pagination — a `page` query param the caller increments. */
    case Page = 'page';
    /** Cursor/token pagination — the next request carries a token read from the body. */
    case Cursor = 'cursor';
    /** Not paginated (the default). */
    case None = 'none';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
