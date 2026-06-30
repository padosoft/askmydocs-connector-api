<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Support;

enum RouteMode: string
{
    /** Fase 1 — the route is exposed as a live LLM tool. */
    case Tool = 'tool';
    /** Fase 2 (reserved) — the route is crawled and indexed into the vector store. */
    case Ingest = 'ingest';
    /** Fase 2 (reserved) — both live tool and ingest. */
    case Both = 'both';

    /** Whether this mode contributes a live tool to the chat loop. */
    public function exposesTool(): bool
    {
        return $this === self::Tool || $this === self::Both;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $m): string => $m->value, self::cases());
    }
}
