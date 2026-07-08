<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Unit;

use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteParameter;
use Padosoft\AskMyDocsConnectorApi\Services\SchemaInferrer;
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
