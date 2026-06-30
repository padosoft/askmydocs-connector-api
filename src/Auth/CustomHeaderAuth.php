<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Auth;

use Padosoft\AskMyDocsConnectorApi\Models\ApiAuthProfile;

/**
 * One or more arbitrary secret headers.
 *   credentials: { headers: { "X-Tenant": "42", "X-Signature": "..." } }
 */
final class CustomHeaderAuth implements AuthApplier
{
    public function material(ApiAuthProfile $profile): AuthMaterial
    {
        $credentials = $profile->credentials;
        $headers = is_array($credentials) ? ($credentials['headers'] ?? null) : null;
        if (! is_array($headers)) {
            return AuthMaterial::none();
        }

        $clean = [];
        foreach ($headers as $name => $value) {
            if (is_string($name) && $name !== '' && is_scalar($value)) {
                $clean[$name] = (string) $value;
            }
        }

        return new AuthMaterial(headers: $clean);
    }
}
