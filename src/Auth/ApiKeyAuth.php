<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Auth;

use Padosoft\AskMyDocsConnectorApi\Models\ApiAuthProfile;

/**
 * API key auth — the key goes in a header (default `X-API-Key`) or in the query
 * string, controlled by the profile `config`:
 *   credentials: { key: "<secret>" }
 *   config:      { in: "header"|"query", name: "X-API-Key" }
 */
final class ApiKeyAuth implements AuthApplier
{
    public function material(ApiAuthProfile $profile): AuthMaterial
    {
        $key = $profile->credential('key') ?? $profile->credential('api_key');
        if ($key === null || $key === '') {
            return AuthMaterial::none();
        }

        $config = is_array($profile->config) ? $profile->config : [];
        $in = strtolower((string) ($config['in'] ?? 'header'));
        $name = (string) ($config['name'] ?? 'X-API-Key');
        if ($name === '') {
            $name = 'X-API-Key';
        }

        if ($in === 'query') {
            return new AuthMaterial(query: [$name => $key]);
        }

        return new AuthMaterial(headers: [$name => $key]);
    }
}
