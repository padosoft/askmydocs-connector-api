<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Services;

use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteParameter;
use Padosoft\AskMyDocsConnectorApi\Support\EndpointType;
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
            'required' => $required,
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
     * Conventional envelope keys under which an API nests its item collection.
     * A property with one of these names holding a JSON array is treated as the
     * list's items, in this precedence order.
     */
    private const LIST_ENVELOPE_KEYS = ['data', 'items', 'results', 'records', 'rows', 'list'];

    /**
     * Classify a decoded response body as a list (collection) vs a detail
     * (single resource), and — for a list — the dot-path to the item array.
     *
     * Rules (auto-detection; the operator can override + lock):
     *  - non-array (scalar / non-JSON) ⇒ Unknown.
     *  - top-level JSON array ⇒ List, items_path '' (the whole body is the
     *    collection — even an array of scalars, though not drillable).
     *  - object with a conventional envelope key ({@see LIST_ENVELOPE_KEYS})
     *    holding an array ⇒ List, items_path = that key. This deliberately checks
     *    the value IS a list, so `{data:{…}}` (a wrapped single resource) stays a
     *    Detail.
     *  - object with exactly ONE non-empty array-of-objects property ⇒ List,
     *    items_path = that key.
     *  - object with several ambiguous array-of-objects properties ⇒ Unknown
     *    (force an operator override rather than guessing).
     *  - any other object ⇒ Detail. (A deeply nested collection like
     *    `{result:{orders:[…]}}` returns Detail; the operator types the dot-path
     *    `result.orders` into items_path to correct it.)
     *
     * @return array{type: EndpointType, items_path: string|null}
     */
    public function classifyEndpoint(mixed $body): array
    {
        if (! is_array($body)) {
            return ['type' => EndpointType::Unknown, 'items_path' => null];
        }

        if (array_is_list($body)) {
            return ['type' => EndpointType::List, 'items_path' => ''];
        }

        foreach (self::LIST_ENVELOPE_KEYS as $key) {
            if (array_key_exists($key, $body) && is_array($body[$key]) && array_is_list($body[$key])) {
                return ['type' => EndpointType::List, 'items_path' => $key];
            }
        }

        $objectListKeys = [];
        foreach ($body as $key => $value) {
            if (is_array($value) && array_is_list($value) && $value !== []
                && is_array($value[0]) && ! array_is_list($value[0])) {
                $objectListKeys[] = (string) $key;
            }
        }

        if (count($objectListKeys) === 1) {
            return ['type' => EndpointType::List, 'items_path' => $objectListKeys[0]];
        }

        if (count($objectListKeys) > 1) {
            return ['type' => EndpointType::Unknown, 'items_path' => null];
        }

        return ['type' => EndpointType::Detail, 'items_path' => null];
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
