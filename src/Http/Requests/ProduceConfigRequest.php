<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Padosoft\AskMyDocsConnectorApi\Http\Requests\Concerns\ValidatesRouteConfig;

/**
 * Validates the "Configura con AI" body — the current config JSON to fill,
 * optional LLM example args, and an optional OpenAPI spec URL (an alternative,
 * authoritative producer). The route need not exist yet (create mode).
 */
final class ProduceConfigRequest extends FormRequest
{
    use ValidatesRouteConfig;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return array_merge($this->routeConfigRules(requireIdentity: false), [
            'example_args' => ['nullable', 'array'],
            'openapi_url' => ['nullable', 'string', 'url', 'max:2048'],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function config(): array
    {
        $config = $this->validated('config');

        return is_array($config) ? $config : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function exampleArgs(): array
    {
        $args = $this->validated('example_args');

        return is_array($args) ? $args : [];
    }

    public function openApiUrl(): ?string
    {
        $url = $this->validated('openapi_url');

        return is_string($url) && $url !== '' ? $url : null;
    }
}
