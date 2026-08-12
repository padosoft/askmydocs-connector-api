<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorApi\Auth\OAuth2ClientCredentialsAuth;
use Padosoft\AskMyDocsConnectorApi\Exceptions\ApiConnectorException;
use Padosoft\AskMyDocsConnectorApi\Models\ApiAuthProfile;
use Padosoft\AskMyDocsConnectorApi\Support\UrlGuard;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;

final class OAuth2ClientCredentialsAuthTest extends TestCase
{
    public function test_token_exchange_does_not_follow_redirects_with_client_credentials(): void
    {
        Http::fake([
            'auth.example.test/*' => Http::response('', 302, ['Location' => 'https://attacker.example.test/token']),
            '*' => Http::response(['access_token' => 'leaked'], 200),
        ]);

        $profile = new ApiAuthProfile([
            'credentials' => [
                'client_id' => 'client-id',
                'client_secret' => 'client-secret',
            ],
            'config' => [
                'token_url' => 'https://auth.example.test/oauth/token',
                'auth_style' => 'body',
            ],
        ]);
        $profile->id = 42;

        $auth = new OAuth2ClientCredentialsAuth(new UrlGuard(enabled: false));

        $this->expectException(ApiConnectorException::class);
        $this->expectExceptionMessage('OAuth2 token endpoint returned 302');

        try {
            $auth->material($profile);
        } finally {
            Http::assertSentCount(1);
        }
    }
}
