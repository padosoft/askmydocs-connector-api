<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Unit;

use Illuminate\Support\Collection;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteParameter;
use Padosoft\AskMyDocsConnectorApi\Support\PaginationDetector;
use Padosoft\AskMyDocsConnectorApi\Support\ParamLocation;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;

/**
 * {@see PaginationDetector} guesses page vs cursor pagination from a sample body
 * + the route's own query params/URL. Cursor signals in the body win; nothing
 * recognised → null (the service then asks the AI, and the operator confirms).
 */
final class PaginationDetectorTest extends TestCase
{
    /**
     * @param  list<string>  $queryParams
     */
    private function route(string $url, array $queryParams = [], ?string $itemsPath = null): ApiRoute
    {
        $route = new ApiRoute;
        $route->url = $url;
        $route->items_path = $itemsPath;

        $params = new Collection(array_map(function (string $name): ApiRouteParameter {
            $p = new ApiRouteParameter;
            $p->name = $name;
            $p->location = ParamLocation::Query;

            return $p;
        }, $queryParams));
        $route->setRelation('parameters', $params);

        return $route;
    }

    public function test_detects_page_number_from_a_declared_query_param(): void
    {
        $config = (new PaginationDetector)->detect(
            $this->route('https://api.example.com/list', ['page', 'per_page'], 'data'),
            ['data' => [['id' => 1]]],
        );

        $this->assertSame('page', $config['type']);
        $this->assertSame('page', $config['page_param']);
        $this->assertSame('per_page', $config['size_param']);
        $this->assertSame('data', $config['items_path']);
        $this->assertSame(1, $config['start_page']);
    }

    public function test_detects_page_and_size_from_the_url_query_string(): void
    {
        $config = (new PaginationDetector)->detect(
            $this->route('https://api.example.com/list?page=1&limit=20'),
            ['x' => 1],
        );

        $this->assertSame('page', $config['type']);
        $this->assertSame('page', $config['page_param']);
        $this->assertSame('limit', $config['size_param']);
    }

    public function test_detects_a_cursor_token_in_an_envelope(): void
    {
        $config = (new PaginationDetector)->detect(
            $this->route('https://api.example.com/list', [], 'items'),
            ['items' => [], 'meta' => ['next_cursor' => 'abc123']],
        );

        $this->assertSame('cursor', $config['type']);
        $this->assertSame('meta.next_cursor', $config['next_cursor_path']);
        $this->assertArrayNotHasKey('next_url_path', $config);

        // No cursor_param, because this route declares no query parameter that
        // could carry one. It used to emit 'cursor' regardless -- invented from
        // nothing, and because a deterministic value OVERRIDES the AI's, that
        // guess replaced a correct suggestion with a parameter the endpoint may
        // not accept. Saying nothing lets the AI's answer stand.
        $this->assertArrayNotHasKey('cursor_param', $config);
    }

    public function test_the_cursor_param_comes_from_the_route_not_from_the_response_key(): void
    {
        // What a response CALLS its token and what the endpoint ACCEPTS it
        // under are routinely different: `next_cursor` out, `after` in.
        $config = (new PaginationDetector)->detect(
            $this->route('https://api.example.com/list', ['after', 'limit'], 'items'),
            ['items' => [], 'meta' => ['next_cursor' => 'abc123']],
        );

        $this->assertSame('cursor', $config['type']);
        $this->assertSame('after', $config['cursor_param']);
        $this->assertSame('meta.next_cursor', $config['next_cursor_path']);
    }

    public function test_detects_a_next_url(): void
    {
        $config = (new PaginationDetector)->detect(
            $this->route('https://api.example.com/list'),
            ['links' => ['next' => 'https://api.example.com/list?page=2']],
        );

        $this->assertSame('cursor', $config['type']);
        $this->assertSame('links.next', $config['next_url_path']);
        $this->assertArrayNotHasKey('next_cursor_path', $config);
    }

    public function test_cursor_signal_wins_over_a_page_param(): void
    {
        $config = (new PaginationDetector)->detect(
            $this->route('https://api.example.com/list?page=1'),
            ['next_cursor' => 'x'],
        );

        $this->assertSame('cursor', $config['type']);
    }

    public function test_returns_null_when_nothing_is_recognised(): void
    {
        $config = (new PaginationDetector)->detect(
            $this->route('https://api.example.com/thing/1', ['id']),
            ['id' => 1, 'name' => 'x'],
        );

        $this->assertNull($config);
    }
}
