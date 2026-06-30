<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Auth;

use Padosoft\AskMyDocsConnectorApi\Models\ApiAuthProfile;

/**
 * HTTP Basic auth — `Authorization: Basic base64(username:password)`.
 *   credentials: { username: "...", password: "..." }
 */
final class BasicAuth implements AuthApplier
{
    public function material(ApiAuthProfile $profile): AuthMaterial
    {
        $username = $profile->credential('username') ?? '';
        $password = $profile->credential('password') ?? '';
        if ($username === '' && $password === '') {
            return AuthMaterial::none();
        }

        $encoded = base64_encode($username.':'.$password);

        return new AuthMaterial(headers: ['Authorization' => 'Basic '.$encoded]);
    }
}
