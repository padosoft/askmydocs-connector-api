<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Auth;

/**
 * The secret request material an auth profile contributes: extra headers and/or
 * query parameters. Produced by an {@see AuthApplier} and merged into the
 * request by the executor. These values are SECRET — the executor never logs
 * them (only `llm`/`fixed` params are logged).
 */
final class AuthMaterial
{
    /**
     * @param  array<string,string>  $headers
     * @param  array<string,string>  $query
     */
    public function __construct(
        public readonly array $headers = [],
        public readonly array $query = [],
    ) {}

    public static function none(): self
    {
        return new self();
    }
}
