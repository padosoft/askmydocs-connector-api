<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Contracts;

/**
 * The AI producer for a route's canonical config JSON ("Configura con AI").
 *
 * A SINGLE call takes how the endpoint is called + a truncated live response
 * sample + the target config JSON Schema + a deterministic seed, and returns the
 * whole filled config. The host binds an implementation backed by its AI
 * manager; the package ships a no-op default ({@see NullResponseAnalyst}) so the
 * connector works without the host — the deterministic seed is used instead.
 */
interface ResponseAnalyst
{
    /**
     * Produce the ENTIRE canonical config JSON for a route in a SINGLE call.
     *
     * The model is given how the endpoint is called (method + url + the args
     * used), a truncated live response sample (+reduction notes), the target
     * config JSON Schema, and a deterministic seed (endpoint_type / items_path /
     * pagination the host already computed exactly). It returns a config JSON —
     * grouped identity·request·response·options — that the caller sanitizes and
     * feeds straight to the codec. Null when unavailable (Null analyst / failed
     * parse); the caller then falls back to the deterministic seed.
     *
     * @param  array{
     *     method: string,
     *     url: string,
     *     example_args: array<string,mixed>,
     *     reduced: mixed,
     *     notes: list<array<string,mixed>>,
     *     schema: array<string,mixed>,
     *     seed: array<string,mixed>,
     *     current?: array<string,mixed>
     * }  $context
     * @return array<string,mixed>|null a config JSON (unsanitized), or null
     */
    public function produceConfig(array $context): ?array;
}
