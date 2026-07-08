<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Unit;

use Padosoft\AskMyDocsConnectorApi\Auth\ApiKeyAuth;
use Padosoft\AskMyDocsConnectorApi\Auth\AuthApplierFactory;
use Padosoft\AskMyDocsConnectorApi\Auth\AuthMaterial;
use Padosoft\AskMyDocsConnectorApi\Auth\BasicAuth;
use Padosoft\AskMyDocsConnectorApi\Auth\BearerAuth;
use Padosoft\AskMyDocsConnectorApi\Auth\CustomHeaderAuth;
use Padosoft\AskMyDocsConnectorApi\Auth\NoneAuth;
use Padosoft\AskMyDocsConnectorApi\Auth\OAuth2ClientCredentialsAuth;
use Padosoft\AskMyDocsConnectorApi\Models\ApiAuthProfile;
use Padosoft\AskMyDocsConnectorApi\Support\AuthType;
use Padosoft\AskMyDocsConnectorApi\Support\UrlGuard;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;

/**
 * {@see AuthApplierFactory} maps each {@see AuthType} to its strategy and turns a
 * profile into the secret {@see AuthMaterial}
 * (headers / query) merged into the outbound request. Credentials round-trip
 * through the `encrypted:array` cast (app.key is set by the base TestCase).
 */
final class AuthApplierFactoryTest extends TestCase
{
    private AuthApplierFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        // UrlGuard only needed by the oauth2 strategy; disabled here.
        $this->factory = new AuthApplierFactory(new UrlGuard(enabled: false));
    }

    public function test_maps_every_auth_type_to_its_strategy(): void
    {
        $this->assertInstanceOf(NoneAuth::class, $this->factory->for(AuthType::None));
        $this->assertInstanceOf(ApiKeyAuth::class, $this->factory->for(AuthType::ApiKey));
        $this->assertInstanceOf(BearerAuth::class, $this->factory->for(AuthType::Bearer));
        $this->assertInstanceOf(BasicAuth::class, $this->factory->for(AuthType::Basic));
        $this->assertInstanceOf(CustomHeaderAuth::class, $this->factory->for(AuthType::Custom));
        $this->assertInstanceOf(
            OAuth2ClientCredentialsAuth::class,
            $this->factory->for(AuthType::OAuth2ClientCredentials),
        );
    }

    public function test_material_for_null_profile_is_empty(): void
    {
        $material = $this->factory->materialFor(null);

        $this->assertSame([], $material->headers);
        $this->assertSame([], $material->query);
    }

    public function test_bearer_injects_authorization_header(): void
    {
        $profile = $this->profile(AuthType::Bearer, credentials: ['token' => 't0k3n']);

        $material = $this->factory->materialFor($profile);

        $this->assertSame(['Authorization' => 'Bearer t0k3n'], $material->headers);
        $this->assertSame([], $material->query);
    }

    public function test_api_key_in_header(): void
    {
        $profile = $this->profile(
            AuthType::ApiKey,
            credentials: ['key' => 'abc'],
            config: ['in' => 'header', 'name' => 'X-Api-Key'],
        );

        $material = $this->factory->materialFor($profile);

        $this->assertSame(['X-Api-Key' => 'abc'], $material->headers);
        $this->assertSame([], $material->query);
    }

    public function test_api_key_in_query(): void
    {
        $profile = $this->profile(
            AuthType::ApiKey,
            credentials: ['key' => 'abc'],
            config: ['in' => 'query', 'name' => 'apikey'],
        );

        $material = $this->factory->materialFor($profile);

        $this->assertSame(['apikey' => 'abc'], $material->query);
        $this->assertSame([], $material->headers);
    }

    public function test_basic_auth_base64_encodes_credentials(): void
    {
        $profile = $this->profile(AuthType::Basic, credentials: ['username' => 'u', 'password' => 'p']);

        $material = $this->factory->materialFor($profile);

        $this->assertSame(
            ['Authorization' => 'Basic '.base64_encode('u:p')],
            $material->headers,
        );
    }

    public function test_custom_headers_pass_through(): void
    {
        $profile = $this->profile(
            AuthType::Custom,
            credentials: ['headers' => ['X-Tenant' => '42', 'X-Signature' => 'sig']],
        );

        $material = $this->factory->materialFor($profile);

        $this->assertSame(['X-Tenant' => '42', 'X-Signature' => 'sig'], $material->headers);
    }

    public function test_none_auth_contributes_nothing(): void
    {
        $profile = $this->profile(AuthType::None, credentials: ['key' => 'ignored']);

        $material = $this->factory->materialFor($profile);

        $this->assertSame([], $material->headers);
        $this->assertSame([], $material->query);
    }

    /**
     * @param  array<string,mixed>|null  $credentials
     * @param  array<string,mixed>|null  $config
     */
    private function profile(AuthType $type, ?array $credentials = null, ?array $config = null): ApiAuthProfile
    {
        $profile = new ApiAuthProfile;
        $profile->type = $type;
        $profile->credentials = $credentials;
        $profile->config = $config;

        return $profile;
    }
}
