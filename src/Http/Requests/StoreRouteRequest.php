<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\Concerns\ValidatesRouteParameters;
use Padosoft\AskMyDocsConnectorApi\Support\HttpMethod;
use Padosoft\AskMyDocsConnectorApi\Support\RouteMode;

/**
 * Validates the create-route payload (spec §4). `project_key`, `status` and the
 * generated artifacts are NOT operator input — the service derives them.
 */
final class StoreRouteRequest extends FormRequest
{
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
        return array_merge([
            'name' => ['required', 'string', 'max:128'],
            'slug' => ['nullable', 'string', 'max:96'],
            'description' => ['nullable', 'string'],
            'http_method' => ['required', Rule::in(HttpMethod::values())],
            'url' => ['required', 'string', 'max:2048'],
            'auth_profile_id' => ['nullable', 'integer'],
            'mode' => ['nullable', Rule::in(RouteMode::values())],
            'timeout_ms' => ['nullable', 'integer', 'min:1'],
            'cache_ttl_s' => ['nullable', 'integer', 'min:0'],
            'rate_limit' => ['nullable', 'integer', 'min:0'],
            'output_transform' => ['nullable', 'array'],
        ], $this->routeParameterRules(required: false));
    }
}
