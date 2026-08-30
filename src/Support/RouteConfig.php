<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Support;

use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;

/**
 * The canonical **config JSON** codec for a route.
 *
 * One route = one grouped config object (identity · request · response ·
 * options). This object is the single source of truth for the UI, the AI, and
 * the API — but the storage stays the normalized columns/tables the runtime
 * already reads. This codec is the ONLY bridge between the two:
 *
 *  - {@see self::fromRoute()} serializes a persisted route → config JSON.
 *  - {@see self::applyToRoute()} un-groups a config JSON → the flat payload
 *    that {@see \Padosoft\AskMyDocsConnectorApi\Services\ConnectorAdminService::createRoute()}
 *    / {@see \Padosoft\AskMyDocsConnectorApi\Services\ConnectorAdminService::updateRoute()}
 *    already accept. So persistence + the runtime tool-call path are untouched.
 *
 * The round-trip is lossless over the operator surface: applying
 * `fromRoute($r)` back through `updateRoute` reproduces `$r`'s operator columns
 * + parameters, and `fromRoute` of the result is byte-for-byte identical.
 * Derived columns (input/output_schema, tool_definition, param_mapping, status,
 * last_test_*, project_key) are outputs of test/lifecycle, never part of the
 * config, and are deliberately excluded.
 *
 * The wire value `response.endpoint_type = 'auto'` encodes an UNLOCKED route
 * (the detector owns the taxonomy); `list`/`detail` encode a locked override.
 * The `endpoint_type_locked` boolean is folded into that single string and
 * reconstructed by {@see ConnectorAdminService::applyEndpointType()} — its exact
 * inverse — so the encoding round-trips even at the raw-column level.
 *
 * Secrets never appear in the config: a `secret` param carries only its
 * `secret_ref` (a credential KEY name), and auth is referenced by
 * `request.auth_profile_id` — the encrypted credentials stay on the profile.
 */
final class RouteConfig
{
    /**
     * Serialize a persisted route into the canonical config JSON.
     *
     * The route's `parameters` relation must be loaded (they carry the request
     * shape). Callers do `$route->loadMissing('parameters')` first, as every
     * read path already does.
     *
     * @return array<string,mixed>
     */
    public static function fromRoute(ApiRoute $route): array
    {
        return [
            'identity' => [
                'name' => $route->name,
                'slug' => $route->slug !== '' ? $route->slug : null,
                'description' => $route->description,
                'mode' => $route->mode->value,
            ],
            'request' => [
                'http_method' => $route->http_method->value,
                'url' => $route->url,
                'auth_profile_id' => $route->auth_profile_id,
                'params' => self::paramsFromRoute($route),
            ],
            'response' => [
                // 'auto' ⇔ the detector owns the taxonomy (unlocked); an explicit
                // list/detail is only surfaced when the operator locked it.
                'endpoint_type' => $route->endpoint_type_locked
                    ? $route->endpoint_type->value
                    : 'auto',
                'items_path' => $route->items_path,
                'transform' => $route->output_transform,
                'pagination' => $route->pagination,
            ],
            'options' => [
                'timeout_ms' => $route->timeout_ms,
                'cache_ttl_s' => $route->cache_ttl_s,
                'rate_limit' => $route->rate_limit,
            ],
        ];
    }

    /**
     * Un-group a config JSON into the flat create/update payload.
     *
     * The result is exactly the shape the FormRequests already produce, so it
     * feeds `createRoute`/`updateRoute` with zero changes to the persistence
     * primitives. It ALWAYS includes `parameters` (even `[]`) and
     * `endpoint_type` + `items_path`, so a config save is authoritatively a
     * full replace — the config is the single source of truth.
     *
     * Pure regroup; no validation (the save endpoint validates against the
     * JSON Schema before calling this).
     *
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    public static function applyToRoute(array $config): array
    {
        $identity = self::group($config, 'identity');
        $request = self::group($config, 'request');
        $response = self::group($config, 'response');
        $options = self::group($config, 'options');

        return [
            'name' => $identity['name'] ?? '',
            'slug' => $identity['slug'] ?? null,
            'description' => $identity['description'] ?? null,
            'mode' => $identity['mode'] ?? RouteMode::Tool->value,
            'http_method' => $request['http_method'] ?? HttpMethod::GET->value,
            'url' => $request['url'] ?? '',
            'auth_profile_id' => $request['auth_profile_id'] ?? null,
            'endpoint_type' => $response['endpoint_type'] ?? 'auto',
            'items_path' => $response['items_path'] ?? null,
            'output_transform' => $response['transform'] ?? null,
            'pagination' => $response['pagination'] ?? null,
            'timeout_ms' => $options['timeout_ms'] ?? null,
            'cache_ttl_s' => $options['cache_ttl_s'] ?? null,
            'rate_limit' => $options['rate_limit'] ?? null,
            'parameters' => self::paramsFromConfig($request),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function paramsFromRoute(ApiRoute $route): array
    {
        $params = [];
        foreach ($route->parameters as $param) {
            $row = [
                'name' => $param->name,
                'location' => $param->location->value,
                'source' => $param->source->value,
                'type' => $param->type->value,
                'required' => $param->required,
                'description' => $param->description,
                'sort_order' => $param->sort_order,
            ];

            // Emit value only for fixed, secret_ref only for secret — mirrors
            // buildParamMapping so the config carries exactly what a source needs.
            if ($param->source === ParamSource::Fixed) {
                $row['value'] = $param->value;
            }
            if ($param->source === ParamSource::Secret) {
                $row['secret_ref'] = $param->secret_ref;
            }

            $params[] = $row;
        }

        return $params;
    }

    /**
     * @param  array<string,mixed>  $request
     * @return list<array<string,mixed>>
     */
    private static function paramsFromConfig(array $request): array
    {
        $raw = $request['params'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $params = [];
        foreach (array_values($raw) as $index => $param) {
            if (! is_array($param)) {
                continue;
            }
            $params[] = [
                'name' => (string) ($param['name'] ?? ''),
                'location' => $param['location'] ?? ParamLocation::Query->value,
                'source' => $param['source'] ?? ParamSource::Llm->value,
                'type' => $param['type'] ?? ParamType::String->value,
                'required' => (bool) ($param['required'] ?? false),
                'value' => $param['value'] ?? null,
                'secret_ref' => $param['secret_ref'] ?? null,
                'description' => $param['description'] ?? null,
                'sort_order' => isset($param['sort_order']) ? (int) $param['sort_order'] : $index,
            ];
        }

        return $params;
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    private static function group(array $config, string $key): array
    {
        $group = $config[$key] ?? [];

        return is_array($group) ? $group : [];
    }
}
