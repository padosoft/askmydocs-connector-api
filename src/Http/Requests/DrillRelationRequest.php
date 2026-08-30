<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the drill-test payload. Provide EITHER an explicit `list_item`
 * object OR an `item_index` into the list route's last test payload (default 0).
 */
final class DrillRelationRequest extends FormRequest
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
            'list_item' => ['nullable', 'array'],
            'item_index' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function listItem(): ?array
    {
        $item = $this->input('list_item');

        return is_array($item) ? $item : null;
    }

    public function itemIndex(): ?int
    {
        $index = $this->input('item_index');

        return is_numeric($index) ? (int) $index : null;
    }
}
