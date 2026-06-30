<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Padosoft\AskMyDocsConnectorApi\Support\AuthType;

/**
 * Validates the update-auth-profile payload. All fields optional (PATCH).
 * `credentials` is merged only when provided so a blank form never wipes the
 * stored secret; it is never echoed back (R21).
 */
final class UpdateAuthProfileRequest extends FormRequest
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
            'type' => ['sometimes', Rule::in(AuthType::values())],
            'credentials' => ['sometimes', 'nullable', 'array'],
            'config' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
