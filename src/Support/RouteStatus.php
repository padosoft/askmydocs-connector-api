<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Support;

enum RouteStatus: string
{
    /** Created, not yet successfully tested. */
    case Draft = 'draft';
    /** A test call succeeded and a schema/tool definition was generated. */
    case Tested = 'tested';
    /** Operator confirmed — the tool is live for chat. */
    case Active = 'active';
    /** Operator paused the route — kept but not exposed. */
    case Disabled = 'disabled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
