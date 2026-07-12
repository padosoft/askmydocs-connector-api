<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Padosoft\AskMyDocsConnectorApi\Support\PaginationType;

/**
 * Validates the "Testa paginazione" payload (spec item 5): the pagination config
 * to exercise + the operator's example values for the llm parameters.
 */
final class TestPaginationRequest extends FormRequest
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
            'pagination' => ['required', 'array'],
            'pagination.type' => ['required', Rule::in(PaginationType::values())],
            'example_args' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function pagination(): array
    {
        $config = $this->validated('pagination', []);

        return is_array($config) ? $config : [];
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
