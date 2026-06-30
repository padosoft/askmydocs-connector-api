<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the "Test connessione" payload (spec §5.1): the operator's example
 * values for the `llm` parameters. Fixed/secret params are resolved server-side.
 */
final class TestRouteRequest extends FormRequest
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
}
