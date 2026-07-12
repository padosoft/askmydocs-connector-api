<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Support;

/**
 * The JSON Schema for the canonical route config JSON + a sanitizer.
 *
 * {@see self::schema()} is the machine-readable target shape handed to the AI
 * (so the model emits exactly the config the codec understands) and documents
 * the save contract. {@see self::sanitize()} coerces an untrusted config (AI
 * output, or a hand-posted body) into a valid grouped config — dropping invalid
 * params, coercing enums to defaults, stripping unknown keys — or returns null
 * when it is fundamentally incomplete (no name / no url). It NEVER throws (R14).
 *
 * The shape mirrors {@see RouteConfig}: identity · request · response · options.
 */
final class RouteConfigSchema
{
    /**
     * The JSON Schema (draft-07) for the canonical config JSON.
     *
     * @return array<string,mixed>
     */
    public static function schema(): array
    {
        $enum = static fn (array $values): array => ['enum' => array_values($values)];

        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['identity', 'request', 'response', 'options'],
            'properties' => [
                'identity' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['name', 'mode'],
                    'properties' => [
                        'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
                        'slug' => ['type' => ['string', 'null'], 'maxLength' => 96],
                        'description' => ['type' => ['string', 'null']],
                        'mode' => $enum(RouteMode::values()),
                    ],
                ],
                'request' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['http_method', 'url', 'params'],
                    'properties' => [
                        'http_method' => $enum(HttpMethod::values()),
                        'url' => ['type' => 'string', 'maxLength' => 2048],
                        'auth_profile_id' => ['type' => ['integer', 'null']],
                        'params' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['name', 'location', 'source', 'type', 'required', 'sort_order'],
                                'properties' => [
                                    'name' => ['type' => 'string', 'maxLength' => 128],
                                    'location' => $enum(ParamLocation::values()),
                                    'source' => $enum(ParamSource::values()),
                                    'type' => $enum(ParamType::values()),
                                    'required' => ['type' => 'boolean'],
                                    'description' => ['type' => ['string', 'null']],
                                    'value' => ['type' => ['string', 'null']],
                                    'secret_ref' => ['type' => ['string', 'null'], 'maxLength' => 128],
                                    'sort_order' => ['type' => 'integer', 'minimum' => 0],
                                ],
                            ],
                        ],
                    ],
                ],
                'response' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['endpoint_type'],
                    'properties' => [
                        'endpoint_type' => ['enum' => ['auto', EndpointType::List->value, EndpointType::Detail->value]],
                        'items_path' => ['type' => ['string', 'null'], 'maxLength' => 255],
                        'transform' => [
                            'type' => ['object', 'null'],
                            'additionalProperties' => false,
                            'properties' => [
                                'include' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'exclude' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                        ],
                        'pagination' => [
                            'type' => ['object', 'null'],
                            'properties' => ['type' => $enum(PaginationType::values())],
                        ],
                    ],
                ],
                'options' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'timeout_ms' => ['type' => ['integer', 'null'], 'minimum' => 1],
                        'cache_ttl_s' => ['type' => ['integer', 'null'], 'minimum' => 0],
                        'rate_limit' => ['type' => ['integer', 'null'], 'minimum' => 0],
                    ],
                ],
            ],
        ];
    }

    /**
     * Coerce an untrusted config into a valid grouped config, or null when it is
     * fundamentally incomplete (no name / no url). Drops invalid params, coerces
     * enums to safe defaults, strips unknown keys. Never throws.
     *
     * @return array<string,mixed>|null
     */
    public static function sanitize(mixed $config): ?array
    {
        if (! is_array($config)) {
            return null;
        }

        $identity = self::obj($config, 'identity');
        $request = self::obj($config, 'request');
        $response = self::obj($config, 'response');
        $options = self::obj($config, 'options');

        $name = self::str($identity['name'] ?? null);
        $url = self::str($request['url'] ?? null);
        if ($name === null || $url === null) {
            return null; // a config without a callable target is unusable
        }

        return [
            'identity' => [
                'name' => $name,
                'slug' => self::str($identity['slug'] ?? null),
                'description' => self::str($identity['description'] ?? null),
                'mode' => self::enum($identity['mode'] ?? null, RouteMode::values(), RouteMode::Tool->value),
            ],
            'request' => [
                'http_method' => self::enum($request['http_method'] ?? null, HttpMethod::values(), HttpMethod::GET->value),
                'url' => $url,
                'auth_profile_id' => self::intOrNull($request['auth_profile_id'] ?? null),
                'params' => self::sanitizeParams($request['params'] ?? null),
            ],
            'response' => [
                'endpoint_type' => self::enum($response['endpoint_type'] ?? null, ['auto', EndpointType::List->value, EndpointType::Detail->value], 'auto'),
                'items_path' => self::str($response['items_path'] ?? null),
                'transform' => self::sanitizeTransform($response['transform'] ?? null),
                'pagination' => self::sanitizePagination($response['pagination'] ?? null),
            ],
            'options' => [
                'timeout_ms' => self::intOrNull($options['timeout_ms'] ?? null),
                'cache_ttl_s' => self::intOrNull($options['cache_ttl_s'] ?? null),
                'rate_limit' => self::intOrNull($options['rate_limit'] ?? null),
            ],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function sanitizeParams(mixed $params): array
    {
        if (! is_array($params)) {
            return [];
        }

        $out = [];
        foreach (array_values($params) as $index => $param) {
            if (! is_array($param)) {
                continue;
            }
            $name = self::str($param['name'] ?? null);
            if ($name === null) {
                continue; // a nameless param is meaningless — drop it
            }

            $source = self::enum($param['source'] ?? null, ParamSource::values(), ParamSource::Llm->value);
            $row = [
                'name' => $name,
                'location' => self::enum($param['location'] ?? null, ParamLocation::values(), ParamLocation::Query->value),
                'source' => $source,
                'type' => self::enum($param['type'] ?? null, ParamType::values(), ParamType::String->value),
                'required' => (bool) ($param['required'] ?? false),
                'description' => self::str($param['description'] ?? null),
                'sort_order' => isset($param['sort_order']) && is_numeric($param['sort_order']) ? (int) $param['sort_order'] : $index,
            ];
            if ($source === ParamSource::Fixed->value) {
                $row['value'] = self::str($param['value'] ?? null);
            }
            if ($source === ParamSource::Secret->value) {
                $row['secret_ref'] = self::str($param['secret_ref'] ?? null);
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function sanitizeTransform(mixed $transform): ?array
    {
        if (! is_array($transform)) {
            return null;
        }
        $include = self::stringList($transform['include'] ?? null);
        $exclude = self::stringList($transform['exclude'] ?? null);
        if ($include === [] && $exclude === []) {
            return null;
        }

        return ['include' => $include, 'exclude' => $exclude];
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function sanitizePagination(mixed $pagination): ?array
    {
        if (! is_array($pagination)) {
            return null;
        }
        $type = self::str($pagination['type'] ?? null);
        if ($type === null || ! in_array($type, PaginationType::values(), true) || $type === PaginationType::None->value) {
            return null;
        }

        // Keep the recognised keys verbatim (they are stored as-is by the codec).
        $keep = ['type', 'page_param', 'size_param', 'start_page', 'cursor_param', 'next_cursor_path', 'next_url_path', 'items_path'];
        $out = [];
        foreach ($keep as $key) {
            if (array_key_exists($key, $pagination) && $pagination[$key] !== null) {
                $out[$key] = $pagination[$key];
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    private static function obj(array $config, string $key): array
    {
        $group = $config[$key] ?? [];

        return is_array($group) ? $group : [];
    }

    private static function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return $value;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function enum(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
