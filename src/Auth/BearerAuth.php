<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Auth;

use Padosoft\AskMyDocsConnectorApi\Models\ApiAuthProfile;

/**
 * Bearer token auth — `Authorization: Bearer <token>`.
 *   credentials: { token: "<secret>" }
 */
final class BearerAuth implements AuthApplier
{
    public function material(ApiAuthProfile $profile): AuthMaterial
    {
        $token = $profile->credential('token') ?? $profile->credential('access_token');
        if ($token === null || $token === '') {
            return AuthMaterial::none();
        }

        return new AuthMaterial(headers: ['Authorization' => 'Bearer '.$token]);
    }
}
