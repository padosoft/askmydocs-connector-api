<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Padosoft\AskMyDocsConnectorApi\Models\ApiAuthProfile;

/**
 * Public shape of an auth profile (R21 / secret-hiding posture).
 *
 * NEVER exposes `credentials`: it returns only the non-secret `type` + `config`
 * plus a `has_credentials` boolean so the UI can show whether the profile is
 * configured without ever leaking the encrypted material. Mirrors the host's
 * ConnectorInstallationResource posture.
 *
 * @mixin ApiAuthProfile
 */
final class ApiAuthProfileResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        $credentials = $this->credentials;

        return [
            'id' => $this->id,
            'api_connector_id' => $this->api_connector_id,
            'type' => $this->type->value,
            'config' => is_array($this->config) ? $this->config : [],
            'has_credentials' => is_array($credentials) && $credentials !== [],
        ];
    }
}
