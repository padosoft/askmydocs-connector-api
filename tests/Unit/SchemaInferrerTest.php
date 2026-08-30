<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Unit;

use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteParameter;
use Padosoft\AskMyDocsConnectorApi\Services\SchemaInferrer;
use Padosoft\AskMyDocsConnectorApi\Support\EndpointType;
use Padosoft\AskMyDocsConnectorApi\Support\ParamLocation;
use Padosoft\AskMyDocsConnectorApi\Support\ParamSource;
use Padosoft\AskMyDocsConnectorApi\Support\ParamType;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;

/**
 * {@see SchemaInferrer} builds the tool's INPUT schema from the declared params
 * (exposing ONLY `source = llm`) and infers the OUTPUT schema from a sample
 * body. Fixed/secret params must never leak into the LLM-visible input.
 */
final class SchemaInferrerTest extends TestCase
{
    private SchemaInferrer $inferrer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inferrer = new SchemaInferrer;
    }

    public function test_infer_input_exposes_only_llm_params_and_collects_required(): void
    {
        $params = [
            $this->param('q', ParamSource::Llm, ParamType::String, required: true, description: 'search text'),
            $this->param('limit', ParamSource::Llm, ParamType::Integer, required: false),
            $this->param('api_key', ParamSource::Secret, ParamType::String, required: true),
            $this->param('format', ParamSource::Fixed, ParamType::String, required: false),
        ];

        $schema = $this->inferrer->inferInput($params);

        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('q', $schema['properties']);
        $this->assertArrayHasKey('limit', $schema['properties']);
        // Fixed + secret params are internal — never exposed.
        $this->assertArrayNotHasKey('api_key', $schema['properties']);
        $this->assertArrayNotHasKey('format', $schema['properties']);

        $this->assertSame(['type' => 'string', 'description' => 'search text'], $schema['properties']['q']);
        $this->assertSame(['type' => 'integer'], $schema['properties']['limit']);
        $this->assertSame(['q'], $schema['required']);
    }

    public function test_infer_output_types_scalars_objects_and_lists(): void
    {
        $schema = $this->inferrer->inferOutput([
            'id' => 7,
            'name' => 'Ada',
            'active' => true,
            'score' => 4.5,
            'tags' => ['a', 'b'],
            'meta' => ['verified' => true],
        ]);

        $this->assertSame('object', $schema['type']);
        $this->assertSame('integer', $schema['properties']['id']['type']);
        $this->assertSame('string', $schema['properties']['name']['type']);
        $this->assertSame('boolean', $schema['properties']['active']['type']);
        $this->assertSame('number', $schema['properties']['score']['type']);

        $this->assertSame('array', $schema['properties']['tags']['type']);
        $this->assertSame('string', $schema['properties']['tags']['items']['type']);

        $this->assertSame('object', $schema['properties']['meta']['type']);
        $this->assertSame('boolean', $schema['properties']['meta']['properties']['verified']['type']);
    }

    public function test_infer_output_of_top_level_list(): void
    {
        $schema = $this->inferrer->inferOutput([
            ['id' => 1],
            ['id' => 2],
        ]);

        $this->assertSame('array', $schema['type']);
        $this->assertSame('object', $schema['items']['type']);
        $this->assertSame('integer', $schema['items']['properties']['id']['type']);
    }

    public function test_classify_top_level_array_of_objects_is_a_list(): void
    {
        $c = $this->inferrer->classifyEndpoint([['id' => 1], ['id' => 2]]);

        $this->assertSame(EndpointType::List, $c['type']);
        $this->assertSame('', $c['items_path']);
    }

    public function test_classify_top_level_array_of_scalars_is_a_list_but_still_top_level(): void
    {
        // A top-level array IS the collection even if items are scalars
        // (not drillable, but still a list).
        $c = $this->inferrer->classifyEndpoint(['a', 'b', 'c']);

        $this->assertSame(EndpointType::List, $c['type']);
        $this->assertSame('', $c['items_path']);
    }

    public function test_classify_envelope_key_holding_an_array_is_a_list(): void
    {
        $c = $this->inferrer->classifyEndpoint([
            'data' => [['id' => 1], ['id' => 2]],
            'meta' => ['total' => 2],
        ]);

        $this->assertSame(EndpointType::List, $c['type']);
        $this->assertSame('data', $c['items_path']);
    }

    public function test_classify_empty_envelope_array_is_still_a_list(): void
    {
        $c = $this->inferrer->classifyEndpoint(['results' => []]);

        $this->assertSame(EndpointType::List, $c['type']);
        $this->assertSame('results', $c['items_path']);
    }

    public function test_classify_wrapped_single_resource_is_a_detail(): void
    {
        // `{data:{…}}` — data is an OBJECT, not an array — is a detail wrapper.
        $c = $this->inferrer->classifyEndpoint(['data' => ['id' => 1, 'name' => 'Ada']]);

        $this->assertSame(EndpointType::Detail, $c['type']);
        $this->assertNull($c['items_path']);
    }

    public function test_classify_single_object_is_a_detail(): void
    {
        $c = $this->inferrer->classifyEndpoint(['id' => 1, 'name' => 'Ada', 'tags' => ['x', 'y']]);

        // `tags` is an array of scalars — a field, not a collection.
        $this->assertSame(EndpointType::Detail, $c['type']);
        $this->assertNull($c['items_path']);
    }

    public function test_classify_single_non_conventional_object_list_property_is_a_list(): void
    {
        $c = $this->inferrer->classifyEndpoint(['orders' => [['id' => 1]]]);

        $this->assertSame(EndpointType::List, $c['type']);
        $this->assertSame('orders', $c['items_path']);
    }

    public function test_classify_multiple_ambiguous_object_lists_is_unknown(): void
    {
        $c = $this->inferrer->classifyEndpoint([
            'orders' => [['id' => 1]],
            'invoices' => [['id' => 9]],
        ]);

        $this->assertSame(EndpointType::Unknown, $c['type']);
        $this->assertNull($c['items_path']);
    }

    public function test_classify_non_array_body_is_unknown(): void
    {
        $this->assertSame(EndpointType::Unknown, $this->inferrer->classifyEndpoint('plain text')['type']);
        $this->assertSame(EndpointType::Unknown, $this->inferrer->classifyEndpoint(42)['type']);
        $this->assertNull($this->inferrer->classifyEndpoint(null)['items_path']);
    }

    private function param(
        string $name,
        ParamSource $source,
        ParamType $type,
        bool $required,
        ?string $description = null,
    ): ApiRouteParameter {
        $param = new ApiRouteParameter;
        $param->name = $name;
        $param->location = ParamLocation::Query;
        $param->source = $source;
        $param->type = $type;
        $param->required = $required;
        $param->description = $description;

        return $param;
    }
}
