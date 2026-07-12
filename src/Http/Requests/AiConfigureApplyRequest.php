<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the one-shot "Configura con AI" payload: optional example args for
 * the llm params, and an OPTIONAL OpenAPI spec URL — when present the config is
 * read from the contract instead of inferred from a live response.
 */
final class AiConfigureApplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'example_args' => ['nullable', 'array'],
            'openapi_url' => ['nullable', 'string', 'url', 'max:2048'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function exampleArgs(): array
    {
        $args = $this->validated('example_args', []);

        return is_array($args) ? $args : [];
    }

    public function openApiUrl(): ?string
    {
        $url = $this->validated('openapi_url');

        return is_string($url) && $url !== '' ? $url : null;
    }
}
