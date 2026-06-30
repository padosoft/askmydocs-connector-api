<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorApi\Exceptions\ApiConnectorException;
use Padosoft\AskMyDocsConnectorApi\Models\ApiAuthProfile;
use Padosoft\AskMyDocsConnectorApi\Support\UrlGuard;

/**
 * OAuth2 client-credentials grant. Fetches an access token from the token
 * endpoint (SSRF-guarded), caches it for its lifetime, and injects
 * `Authorization: Bearer <token>`.
 *
 *   credentials: { client_id, client_secret }
 *   config:      { token_url, scope?, auth_style?: "body"|"basic", header_name? }
 *
 * A failed token exchange throws (R14) so the executor returns an error to the
 * LLM rather than silently calling the endpoint unauthenticated.
 */
final class OAuth2ClientCredentialsAuth implements AuthApplier
{
    public function __construct(private readonly UrlGuard $urlGuard) {}

    public function material(ApiAuthProfile $profile): AuthMaterial
    {
        $config = is_array($profile->config) ? $profile->config : [];
        $tokenUrl = (string) ($config['token_url'] ?? '');
        $clientId = $profile->credential('client_id') ?? '';
        $clientSecret = $profile->credential('client_secret') ?? '';

        if ($tokenUrl === '' || $clientId === '') {
            throw new ApiConnectorException('OAuth2 client-credentials profile is missing token_url or client_id.');
        }

        $headerName = (string) ($config['header_name'] ?? 'Authorization');
        $token = $this->token($profile->id, $tokenUrl, $clientId, $clientSecret, $config);

        return new AuthMaterial(headers: [$headerName => 'Bearer '.$token]);
    }

    private function token(int $profileId, string $tokenUrl, string $clientId, string $clientSecret, array $config): string
    {
        $scope = (string) ($config['scope'] ?? '');
        $cacheKey = 'api-connector:oauth2:'.$profileId.':'.md5($tokenUrl.'|'.$scope);

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $this->urlGuard->assertAllowed($tokenUrl);

        $body = ['grant_type' => 'client_credentials'];
        if ($scope !== '') {
            $body['scope'] = $scope;
        }

        $style = strtolower((string) ($config['auth_style'] ?? 'body'));
        $request = Http::asForm()->timeout(15);
        if ($style === 'basic') {
            $request = $request->withBasicAuth($clientId, $clientSecret);
        } else {
            $body['client_id'] = $clientId;
            $body['client_secret'] = $clientSecret;
        }

        $response = $request->post($tokenUrl, $body);
        if (! $response->successful()) {
            throw new ApiConnectorException(
                "OAuth2 token endpoint returned {$response->status()} for profile {$profileId}."
            );
        }

        $data = $response->json();
        $token = is_array($data) ? ($data['access_token'] ?? null) : null;
        if (! is_string($token) || $token === '') {
            throw new ApiConnectorException("OAuth2 token endpoint did not return an access_token for profile {$profileId}.");
        }

        $expiresIn = is_array($data) && isset($data['expires_in']) ? (int) $data['expires_in'] : 300;
        $ttl = max(30, $expiresIn - 30);
        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }
}
