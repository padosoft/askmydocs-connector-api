<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Unit;

use Padosoft\AskMyDocsConnectorApi\Support\RouteConfigSchema;
use PHPUnit\Framework\TestCase;

/**
 * {@see RouteConfigSchema::sanitize()} is the guard between untrusted config
 * (AI output / a hand-posted body) and the codec: it must coerce to a valid
 * grouped config or return null — never throw, never let a bad value through.
 */
final class RouteConfigSchemaTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function valid(): array
    {
        return [
            'identity' => ['name' => 'List users', 'mode' => 'tool'],
            'request' => ['http_method' => 'GET', 'url' => 'https://api.example.com/users', 'params' => []],
            'response' => ['endpoint_type' => 'list'],
            'options' => [],
        ];
    }

    public function test_schema_exposes_the_four_groups(): void
    {
        $props = RouteConfigSchema::schema()['properties'];
        $this->assertSame(['identity', 'request', 'response', 'options'], array_keys($props));
    }

    public function test_a_config_without_name_or_url_is_rejected(): void
    {
        $this->assertNull(RouteConfigSchema::sanitize(['request' => ['url' => 'https://x']]));
        $this->assertNull(RouteConfigSchema::sanitize(['identity' => ['name' => 'X']]));
        $this->assertNull(RouteConfigSchema::sanitize('not an array'));
    }

    public function test_enums_are_coerced_to_safe_defaults(): void
    {
        $config = $this->valid();
        $config['identity']['mode'] = 'bogus';
        $config['request']['http_method'] = 'FETCH';
        $config['response']['endpoint_type'] = 'nonsense';

        $out = RouteConfigSchema::sanitize($config);

        $this->assertSame('tool', $out['identity']['mode']);
        $this->assertSame('GET', $out['request']['http_method']);
        $this->assertSame('auto', $out['response']['endpoint_type']);
    }

    public function test_invalid_params_are_dropped_and_value_secret_ref_are_source_scoped(): void
    {
        $config = $this->valid();
        $config['request']['params'] = [
            ['location' => 'query', 'source' => 'llm'],                                  // no name → dropped
            ['name' => 'q', 'location' => 'weird', 'source' => 'weird', 'type' => 'weird'], // enums coerced
            ['name' => 'fmt', 'location' => 'query', 'source' => 'fixed', 'value' => 'json', 'secret_ref' => 'leak'],
            ['name' => 'key', 'location' => 'header', 'source' => 'secret', 'secret_ref' => 'api_key', 'value' => 'nope'],
        ];

        $params = RouteConfigSchema::sanitize($config)['request']['params'];

        $this->assertCount(3, $params);
        $this->assertSame('query', $params[0]['location']);   // coerced default
        $this->assertSame('llm', $params[0]['source']);
        $this->assertSame('string', $params[0]['type']);
        // fixed carries value only; secret carries secret_ref only.
        $this->assertSame('json', $params[1]['value']);
        $this->assertArrayNotHasKey('secret_ref', $params[1]);
        $this->assertSame('api_key', $params[2]['secret_ref']);
        $this->assertArrayNotHasKey('value', $params[2]);
    }

    public function test_pagination_none_and_empty_transform_collapse_to_null(): void
    {
        $config = $this->valid();
        $config['response']['pagination'] = ['type' => 'none'];
        $config['response']['transform'] = ['include' => [], 'exclude' => []];

        $out = RouteConfigSchema::sanitize($config);

        $this->assertNull($out['response']['pagination']);
        $this->assertNull($out['response']['transform']);

        // A real cursor scheme survives, keeping its recognised keys.
        $config['response']['pagination'] = ['type' => 'cursor', 'next_cursor_path' => 'meta.next', 'junk' => 'x'];
        $out = RouteConfigSchema::sanitize($config);
        $this->assertSame(['type' => 'cursor', 'next_cursor_path' => 'meta.next'], $out['response']['pagination']);
    }
}
