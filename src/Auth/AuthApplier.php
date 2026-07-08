<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Auth;

use Padosoft\AskMyDocsConnectorApi\Models\ApiAuthProfile;
use Padosoft\AskMyDocsConnectorApi\Support\AuthType;

/**
 * Strategy that turns an {@see ApiAuthProfile} into the secret {@see AuthMaterial}
 * (headers / query) to inject into an outbound request. One implementation per
 * {@see AuthType}.
 */
interface AuthApplier
{
    public function material(ApiAuthProfile $profile): AuthMaterial;
}
