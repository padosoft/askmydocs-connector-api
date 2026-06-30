<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the create-connector payload (spec §5). Authorization is handled by
 * the host-supplied route middleware (R32); this request only validates shape.
 */
final class StoreConnectorRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string'],
            'project_key' => ['nullable', 'string', 'max:100'],
            'base_url' => ['nullable', 'string', 'max:2048', 'url'],
            'headers' => ['nullable', 'array'],
            'headers.*' => ['string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
