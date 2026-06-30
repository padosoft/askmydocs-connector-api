<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Services;

use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteParameter;
use Padosoft\AskMyDocsConnectorApi\Support\ParamSource;

/**
 * Deduces the input + output JSON schema for a route (spec §5.1 steps 4–5).
 *
 * INPUT: built from the declared parameters, exposing ONLY `source = llm` ones —
 * fixed/secret params stay internal and never enter the tool the LLM sees.
 *
 * OUTPUT: inferred from a sample JSON response body — nested objects map to
 * `object` with `properties`, lists map to `array` with `items` sampled from
 * the first element.
 */
final class SchemaInferrer
{
    /**
     * @param  iterable<ApiRouteParameter>  $parameters
     * @return array{type: string, properties: array<string,mixed>, required: list<string>}
     */
    public function inferInput(iterable $parameters): array
    {
        $properties = [];
        $required = [];

        foreach ($parameters as $param) {
            if ($param->source !== ParamSource::Llm) {
                continue;
            }

            $schema = ['type' => $param->type->value];
            if (is_string($param->description) && $param->description !== '') {
                $schema['description'] = $param->description;
            }
            $properties[$param->name] = $schema;

            if ($param->required) {
                $required[] = $param->name;
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_values($required),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function inferOutput(mixed $body): array
    {
        return $this->describe($body, 0);
    }

    /**
     * @return array<string,mixed>
     */
    private function describe(mixed $value, int $depth): array
    {
        // Guard against pathological nesting blowing the schema up.
        if ($depth > 8) {
            return ['type' => 'object'];
        }

        if (is_array($value)) {
            return array_is_list($value)
                ? $this->describeList($value, $depth)
                : $this->describeObject($value, $depth);
        }

        return ['type' => $this->scalarType($value)];
    }

    /**
     * @param  list<mixed>  $list
     * @return array<string,mixed>
     */
    private function describeList(array $list, int $depth): array
    {
        if ($list === []) {
            return ['type' => 'array', 'items' => ['type' => 'object']];
        }

        return ['type' => 'array', 'items' => $this->describe($list[0], $depth + 1)];
    }

    /**
     * @param  array<string,mixed>  $object
     * @return array<string,mixed>
     */
    private function describeObject(array $object, int $depth): array
    {
        $properties = [];
        foreach ($object as $key => $val) {
            $properties[(string) $key] = $this->describe($val, $depth + 1);
        }

        return ['type' => 'object', 'properties' => $properties];
    }

    private function scalarType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_null($value) => 'null',
            default => 'string',
        };
    }
}
