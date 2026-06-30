<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the "Prova tool" payload (spec §5): the arguments an operator wants
 * to drive the live executor with, exactly as the LLM would supply them.
 */
final class TryRouteRequest extends FormRequest
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
            'arguments' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function arguments(): array
    {
        $args = $this->validated('arguments', []);

        return is_array($args) ? $args : [];
    }
}
