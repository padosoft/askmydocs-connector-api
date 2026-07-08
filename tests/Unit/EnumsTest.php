<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Unit;

use Padosoft\AskMyDocsConnectorApi\Support\AuthType;
use Padosoft\AskMyDocsConnectorApi\Support\HttpMethod;
use Padosoft\AskMyDocsConnectorApi\Support\ParamLocation;
use Padosoft\AskMyDocsConnectorApi\Support\ParamSource;
use Padosoft\AskMyDocsConnectorApi\Support\ParamType;
use Padosoft\AskMyDocsConnectorApi\Support\RouteMode;
use Padosoft\AskMyDocsConnectorApi\Support\RouteStatus;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;

/**
 * Locks the string-backed enum surfaces to their declared cases + values so a
 * future rename/removal is caught (these values are persisted in the varchar
 * columns and quoted in migrations + docs — R9).
 */
final class EnumsTest extends TestCase
{
    public function test_auth_type_has_the_six_expected_cases(): void
    {
        $this->assertSame(
            ['none', 'api_key', 'bearer', 'basic', 'custom', 'oauth2_cc'],
            AuthType::values(),
        );
        $this->assertCount(6, AuthType::cases());
        $this->assertSame('oauth2_cc', AuthType::OAuth2ClientCredentials->value);
    }

    public function test_http_method_values_and_body_semantics(): void
    {
        $this->assertSame(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], HttpMethod::values());

        $this->assertTrue(HttpMethod::POST->allowsBody());
        $this->assertTrue(HttpMethod::PUT->allowsBody());
        $this->assertTrue(HttpMethod::PATCH->allowsBody());
        $this->assertFalse(HttpMethod::GET->allowsBody());
        $this->assertFalse(HttpMethod::DELETE->allowsBody());
    }

    public function test_param_location_cases(): void
    {
        $this->assertSame(['path', 'query', 'header', 'body'], ParamLocation::values());
    }

    public function test_param_source_cases(): void
    {
        $this->assertSame(['llm', 'fixed', 'secret'], ParamSource::values());
    }

    public function test_param_type_cases(): void
    {
        $this->assertSame(
            ['string', 'integer', 'number', 'boolean', 'array', 'object'],
            ParamType::values(),
        );
        $this->assertSame('array', ParamType::Array_->value);
        $this->assertSame('object', ParamType::Object_->value);
    }

    public function test_route_mode_values_and_tool_exposure(): void
    {
        $this->assertSame(['tool', 'ingest', 'both'], RouteMode::values());

        $this->assertTrue(RouteMode::Tool->exposesTool());
        $this->assertTrue(RouteMode::Both->exposesTool());
        $this->assertFalse(RouteMode::Ingest->exposesTool());
    }

    public function test_route_status_cases(): void
    {
        $this->assertSame(['draft', 'tested', 'active', 'disabled'], RouteStatus::values());
    }
}
