<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Unit;

use Padosoft\AskMyDocsConnectorApi\Contracts\NullToolDescriptionAssistant;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Services\ToolDefinitionGenerator;
use Padosoft\AskMyDocsConnectorApi\Support\HttpMethod;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * {@see ToolDefinitionGenerator} produces the public `{name, description,
 * input_schema}` the LLM sees. With the no-op assistant it falls back to a
 * field-derived draft: the slug/name is normalised to a safe tool identifier
 * and an absent description is drafted from the method + path.
 */
final class ToolDefinitionGeneratorTest extends TestCase
{
    private ToolDefinitionGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        // No-op assistant → deterministic field-derived output (suggest() = null).
        $this->generator = new ToolDefinitionGenerator(new NullToolDescriptionAssistant);
    }

    #[DataProvider('slugCases')]
    public function test_normalize_slug(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->generator->normalizeSlug($input));
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function slugCases(): array
    {
        return [
            'spaces to underscores' => ['Get User', 'get_user'],
            'dashes to underscores' => ['weather-now', 'weather_now'],
            'strips punctuation' => ['Ping!?', 'ping'],
            'leading digit gets tool_ prefix' => ['123abc', 'tool_123abc'],
        ];
    }

    public function test_normalize_slug_truncates_to_64_chars(): void
    {
        $slug = $this->generator->normalizeSlug(str_repeat('a', 200));

        $this->assertSame(64, strlen($slug));
    }

    public function test_generate_uses_route_slug_and_description_when_present(): void
    {
        $route = $this->route(slug: 'get_weather', name: 'Weather', description: 'Gets the current weather.');
        $inputSchema = ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']];

        $definition = $this->generator->generate($route, $inputSchema);

        $this->assertSame('get_weather', $definition['name']);
        $this->assertSame('Gets the current weather.', $definition['description']);
        $this->assertSame($inputSchema, $definition['input_schema']);
    }

    public function test_generate_drafts_description_from_method_and_path_when_missing(): void
    {
        $route = $this->route(slug: 'list_orders', name: 'Orders', description: null);

        $definition = $this->generator->generate($route, ['type' => 'object', 'properties' => [], 'required' => []]);

        $this->assertSame('list_orders', $definition['name']);
        $this->assertStringContainsString('Calls GET /v1/orders', $definition['description']);
    }

    public function test_generate_annotates_a_detail_param_and_description_from_inbound_relations(): void
    {
        $route = $this->route(slug: 'user_detail', name: 'User detail', description: 'Fetch a user.');
        $inputSchema = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']];

        $definition = $this->generator->generate($route, $inputSchema, null, [
            'inbound' => [
                ['list_slug' => 'list_users', 'field_map' => [['from' => 'id', 'to_param' => 'id']]],
            ],
        ]);

        // The `id` param learns where its value comes from…
        $this->assertStringContainsString(
            'Typically the `id` field of an item returned by the `list_users` tool.',
            $definition['input_schema']['properties']['id']['description'],
        );
        // …and the tool description tells the model to call the list first.
        $this->assertStringContainsString('Call the `list_users` tool first', $definition['description']);
    }

    public function test_generate_advertises_drill_downs_from_outbound_relations(): void
    {
        $route = $this->route(slug: 'list_users', name: 'List users', description: 'List users.');

        $definition = $this->generator->generate($route, ['type' => 'object', 'properties' => [], 'required' => []], null, [
            'outbound' => [['detail_slug' => 'user_detail']],
        ]);

        $this->assertStringContainsString(
            'Each returned item can be drilled into with the `user_detail` tool.',
            $definition['description'],
        );
    }

    public function test_generate_leaves_the_definition_unchanged_without_relations(): void
    {
        $route = $this->route(slug: 'user_detail', name: 'User detail', description: 'Fetch a user.');
        $inputSchema = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']];

        $definition = $this->generator->generate($route, $inputSchema);

        $this->assertSame('Fetch a user.', $definition['description']);
        $this->assertSame(['type' => 'integer'], $definition['input_schema']['properties']['id']);
    }

    private function route(string $slug, string $name, ?string $description): ApiRoute
    {
        $route = new ApiRoute;
        $route->slug = $slug;
        $route->name = $name;
        $route->description = $description;
        $route->http_method = HttpMethod::GET;
        $route->url = 'https://api.example.com/v1/orders';

        return $route;
    }
}
