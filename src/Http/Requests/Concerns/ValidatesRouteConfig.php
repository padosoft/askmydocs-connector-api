<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Padosoft\AskMyDocsConnectorApi\Support\EndpointType;
use Padosoft\AskMyDocsConnectorApi\Support\HttpMethod;
use Padosoft\AskMyDocsConnectorApi\Support\ParamLocation;
use Padosoft\AskMyDocsConnectorApi\Support\ParamSource;
use Padosoft\AskMyDocsConnectorApi\Support\ParamType;
use Padosoft\AskMyDocsConnectorApi\Support\RouteMode;

/**
 * Nested validation for the canonical config JSON envelope ({@see
 * \Padosoft\AskMyDocsConnectorApi\Support\RouteConfig}). Keys mirror the schema
 * (identity·request·response·options), so a validation failure surfaces as
 * `config.request.url` / `config.request.params.0.name`, which the FE maps back
 * onto the per-field errors.
 */
trait ValidatesRouteConfig
{
    /**
     * @param  bool  $requireIdentity  true for a save (name is required); false for
     *                                 a dry-run test/produce where only the callable
     *                                 target (method + url) matters.
     * @return array<string,mixed>
     */
    protected function routeConfigRules(bool $requireIdentity = true): array
    {
        return [
            'config' => ['required', 'array'],

            'config.identity' => [$requireIdentity ? 'required' : 'sometimes', 'array'],
            'config.identity.name' => [$requireIdentity ? 'required' : 'nullable', 'string', 'max:128'],
            'config.identity.slug' => ['nullable', 'string', 'max:96'],
            'config.identity.description' => ['nullable', 'string'],
            'config.identity.mode' => ['nullable', Rule::in(RouteMode::values())],

            'config.request' => ['required', 'array'],
            'config.request.http_method' => ['required', Rule::in(HttpMethod::values())],
            'config.request.url' => ['required', 'string', 'max:2048'],
            'config.request.auth_profile_id' => ['nullable', 'integer'],
            'config.request.params' => ['sometimes', 'array'],
            'config.request.params.*.name' => ['required_with:config.request.params', 'string', 'max:128'],
            'config.request.params.*.location' => ['required_with:config.request.params', Rule::in(ParamLocation::values())],
            'config.request.params.*.source' => ['required_with:config.request.params', Rule::in(ParamSource::values())],
            'config.request.params.*.type' => ['nullable', Rule::in(ParamType::values())],
            'config.request.params.*.required' => ['nullable', 'boolean'],
            'config.request.params.*.value' => ['nullable', 'string'],
            'config.request.params.*.secret_ref' => ['nullable', 'string', 'max:128'],
            'config.request.params.*.description' => ['nullable', 'string'],
            'config.request.params.*.sort_order' => ['nullable', 'integer', 'min:0'],

            'config.response' => ['sometimes', 'array'],
            'config.response.endpoint_type' => ['nullable', Rule::in(['auto', EndpointType::List->value, EndpointType::Detail->value])],
            'config.response.items_path' => ['nullable', 'string', 'max:255'],
            'config.response.transform' => ['nullable', 'array'],
            'config.response.pagination' => ['nullable', 'array'],

            'config.options' => ['sometimes', 'array'],
            'config.options.timeout_ms' => ['nullable', 'integer', 'min:1'],
            'config.options.cache_ttl_s' => ['nullable', 'integer', 'min:0'],
            'config.options.rate_limit' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
