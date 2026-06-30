<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Padosoft\AskMyDocsConnectorApi\Services\ConnectorAdminService;

/**
 * Validates the update-connector payload. All fields are optional (PATCH
 * semantics); the R28 project_key guard lives in {@see ConnectorAdminService}
 * because it depends on whether the connector already owns routes.
 */
final class UpdateConnectorRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:128'],
            'description' => ['sometimes', 'nullable', 'string'],
            'project_key' => ['sometimes', 'nullable', 'string', 'max:100'],
            'base_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'url'],
            'headers' => ['sometimes', 'nullable', 'array'],
            'headers.*' => ['string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
