<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Support;

/**
 * Response-shape taxonomy of a route — the "Lista vs Dettaglio" discriminator.
 *
 * This is an axis ORTHOGONAL to `RouteMode` (tool|ingest|both, the delivery
 * axis) and `RouteStatus`. It classifies what the endpoint RETURNS:
 *   - List   → a collection (top-level JSON array, or an array-of-objects nested
 *              under an envelope key like `data`/`results`). Drillable into a
 *              detail via an `ApiRouteRelation`.
 *   - Detail → a single resource object.
 *   - Unknown → not yet detected (legacy rows, or an ambiguous/non-JSON body).
 *
 * Auto-detected from the first successful test call (array-of-objects ⇒ List,
 * single object ⇒ Detail); the operator can override and lock the choice.
 * `Unknown` keeps the enum cast total (no nullable-enum handling) and is the
 * honest default for a route that has never been tested — it must NEVER gate
 * tool exposure (`ApiRoute::scopeExposesTool` stays on mode+status only).
 */
enum EndpointType: string
{
    /** A collection of resources (array, possibly under an envelope key). */
    case List = 'list';
    /** A single resource object. */
    case Detail = 'detail';
    /** Not yet detected (legacy row or ambiguous/non-JSON response). */
    case Unknown = 'unknown';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
