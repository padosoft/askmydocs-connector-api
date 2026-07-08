<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Unit;

use Illuminate\Support\Collection;
use Padosoft\AskMyDocsConnectorApi\Exceptions\ApiConnectorException;
use Padosoft\AskMyDocsConnectorApi\Models\ApiAuthProfile;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteParameter;
use Padosoft\AskMyDocsConnectorApi\Support\HttpMethod;
use Padosoft\AskMyDocsConnectorApi\Support\ParamLocation;
use Padosoft\AskMyDocsConnectorApi\Support\ParamSource;
use Padosoft\AskMyDocsConnectorApi\Support\ParamType;
use Padosoft\AskMyDocsConnectorApi\Support\RequestPlan;
use Padosoft\AskMyDocsConnectorApi\Support\RequestPlanner;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;

/**
 * {@see RequestPlanner} resolves the LLM arguments + fixed/secret params into a
 * concrete {@see RequestPlan}: path
 * substitution, query/header/body placement, type coercion, missing-required
 * detection, unresolved-token guard, and — critically — secret params are
 * resolved from the auth profile but NEVER placed in `loggableParams` (R21).
 *
 * Models are built in-memory (no DB): the planner only reads properties + the
 * `parameters` relation, which we seed via setRelation().
 */
final class RequestPlannerTest extends TestCase
{
    private RequestPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = new RequestPlanner;
    }

    public function test_substitutes_path_places_query_and_coerces_types(): void
    {
        $route = $this->route('https://api.example.com/users/{id}', HttpMethod::GET, [
            $this->param('id', ParamLocation::Path, ParamSource::Llm, ParamType::Integer, required: true),
            $this->param('limit', ParamLocation::Query, ParamSource::Llm, ParamType::Integer),
        ]);

        $plan = $this->planner->plan($route, ['id' => '42', 'limit' => '5'], null);

        $this->assertSame('https://api.example.com/users/42', $plan->url);
        $this->assertSame(['limit' => 5], $plan->query);
        $this->assertSame(HttpMethod::GET, $plan->method);
        $this->assertNull($plan->body);
        // llm params are loggable, and coerced.
        $this->assertSame(['id' => 42, 'limit' => 5], $plan->loggableParams);
    }

    public function test_missing_required_llm_param_throws(): void
    {
        $route = $this->route('https://api.example.com/users/{id}', HttpMethod::GET, [
            $this->param('id', ParamLocation::Path, ParamSource::Llm, ParamType::Integer, required: true),
        ]);

        $this->expectException(ApiConnectorException::class);
        $this->expectExceptionMessage('Missing required parameter(s): id');

        $this->planner->plan($route, [], null);
    }

    public function test_secret_param_is_resolved_but_never_logged(): void
    {
        $route = $this->route('https://api.example.com/data', HttpMethod::GET, [
            $this->param(
                'X-Token',
                ParamLocation::Header,
                ParamSource::Secret,
                ParamType::String,
                required: false,
                secretRef: 'token',
            ),
            $this->param('q', ParamLocation::Query, ParamSource::Llm, ParamType::String),
        ]);

        $profile = new ApiAuthProfile;
        $profile->credentials = ['token' => 's3cr3t'];

        $plan = $this->planner->plan($route, ['q' => 'hello'], $profile);

        // Secret reaches the header...
        $this->assertSame(['X-Token' => 's3cr3t'], $plan->headers);
        // ...but is excluded from the loggable params (R21). Only q is logged.
        $this->assertSame(['q' => 'hello'], $plan->loggableParams);
    }

    public function test_fixed_param_uses_configured_value(): void
    {
        $route = $this->route('https://api.example.com/data', HttpMethod::POST, [
            $this->param('format', ParamLocation::Body, ParamSource::Fixed, ParamType::String, value: 'json'),
            $this->param('q', ParamLocation::Body, ParamSource::Llm, ParamType::String),
        ]);

        $plan = $this->planner->plan($route, ['q' => 'ping'], null);

        // POST carries a body; both fixed + llm land in it.
        $this->assertSame(['format' => 'json', 'q' => 'ping'], $plan->body);
        $this->assertSame(['format' => 'json', 'q' => 'ping'], $plan->loggableParams);
    }

    public function test_body_is_dropped_for_methods_that_do_not_carry_one(): void
    {
        $route = $this->route('https://api.example.com/data', HttpMethod::GET, [
            $this->param('note', ParamLocation::Body, ParamSource::Llm, ParamType::String),
        ]);

        $plan = $this->planner->plan($route, ['note' => 'x'], null);

        $this->assertNull($plan->body);
    }

    public function test_unresolved_path_token_throws(): void
    {
        // {region} has no matching param → the URL still carries the token.
        $route = $this->route('https://api.example.com/{region}/data', HttpMethod::GET, [
            $this->param('q', ParamLocation::Query, ParamSource::Llm, ParamType::String),
        ]);

        $this->expectException(ApiConnectorException::class);
        $this->expectExceptionMessage('Unresolved path parameter');

        $this->planner->plan($route, ['q' => 'x'], null);
    }

    /**
     * @param  list<ApiRouteParameter>  $parameters
     */
    private function route(string $url, HttpMethod $method, array $parameters): ApiRoute
    {
        $route = new ApiRoute;
        $route->url = $url;
        $route->http_method = $method;
        $route->setRelation('parameters', new Collection($parameters));

        return $route;
    }

    private function param(
        string $name,
        ParamLocation $location,
        ParamSource $source,
        ParamType $type,
        bool $required = false,
        ?string $value = null,
        ?string $secretRef = null,
    ): ApiRouteParameter {
        $param = new ApiRouteParameter;
        $param->name = $name;
        $param->location = $location;
        $param->source = $source;
        $param->type = $type;
        $param->required = $required;
        $param->value = $value;
        $param->secret_ref = $secretRef;

        return $param;
    }
}
