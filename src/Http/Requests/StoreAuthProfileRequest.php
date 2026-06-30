<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Padosoft\AskMyDocsConnectorApi\Support\AuthType;

/**
 * Validates the create-auth-profile payload. `credentials` is accepted as a
 * free-form object (it is stored encrypted) and is never echoed back (R21).
 */
final class StoreAuthProfileRequest extends FormRequest
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
            'type' => ['required', Rule::in(AuthType::values())],
            'credentials' => ['nullable', 'array'],
            'config' => ['nullable', 'array'],
        ];
    }
}
