<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\Concerns\ValidatesRouteConfig;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\Concerns\ValidatesRouteParameters;
use Padosoft\AskMyDocsConnectorApi\Support\EndpointType;
use Padosoft\AskMyDocsConnectorApi\Support\HttpMethod;
use Padosoft\AskMyDocsConnectorApi\Support\RouteMode;

/**
 * Validates the update-route payload. All fields optional (PATCH). When
 * `parameters` is present it fully replaces the route's parameter set; absent
 * leaves them untouched.
 *
 * A `{config}` envelope is a FULL replace (the config is the single source of
 * truth), so its rules require a complete config just like create.
 */
final class UpdateRouteRequest extends FormRequest
{
    use ValidatesRouteConfig;
    use ValidatesRouteParameters;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        if ($this->has('config')) {
            return $this->routeConfigRules();
        }

        return array_merge([
            'name' => ['sometimes', 'string', 'max:128'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:96'],
            'description' => ['sometimes', 'nullable', 'string'],
            'http_method' => ['sometimes', Rule::in(HttpMethod::values())],
            'url' => ['sometimes', 'string', 'max:2048'],
            'auth_profile_id' => ['sometimes', 'nullable', 'integer'],
            'mode' => ['sometimes', Rule::in(RouteMode::values())],
            'endpoint_type' => ['sometimes', 'nullable', Rule::in(['auto', EndpointType::List->value, EndpointType::Detail->value])],
            'items_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'timeout_ms' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'cache_ttl_s' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'rate_limit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'output_transform' => ['sometimes', 'nullable', 'array'],
            'pagination' => ['sometimes', 'nullable', 'array'],
        ], $this->routeParameterRules(required: false));
    }
}
