<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Auth;

use Padosoft\AskMyDocsConnectorApi\Models\ApiAuthProfile;
use Padosoft\AskMyDocsConnectorApi\Support\AuthType;
use Padosoft\AskMyDocsConnectorApi\Support\UrlGuard;

/**
 * Resolves the {@see AuthApplier} for a given auth type. Centralises the
 * type → strategy mapping so the executor stays agnostic of auth mechanics.
 */
final class AuthApplierFactory
{
    public function __construct(private readonly UrlGuard $urlGuard) {}

    public function for(AuthType $type): AuthApplier
    {
        return match ($type) {
            AuthType::None => new NoneAuth(),
            AuthType::ApiKey => new ApiKeyAuth(),
            AuthType::Bearer => new BearerAuth(),
            AuthType::Basic => new BasicAuth(),
            AuthType::Custom => new CustomHeaderAuth(),
            AuthType::OAuth2ClientCredentials => new OAuth2ClientCredentialsAuth($this->urlGuard),
        };
    }

    /** Convenience: material for a profile (or empty material when none). */
    public function materialFor(?ApiAuthProfile $profile): AuthMaterial
    {
        if ($profile === null) {
            return AuthMaterial::none();
        }

        return $this->for($profile->type)->material($profile);
    }
}
