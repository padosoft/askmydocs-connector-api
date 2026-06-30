<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Auth;

use Padosoft\AskMyDocsConnectorApi\Models\ApiAuthProfile;

/** No authentication — public endpoint. */
final class NoneAuth implements AuthApplier
{
    public function material(ApiAuthProfile $profile): AuthMaterial
    {
        return AuthMaterial::none();
    }
}
