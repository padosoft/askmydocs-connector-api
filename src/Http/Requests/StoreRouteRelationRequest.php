<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Padosoft\AskMyDocsConnectorApi\Support\ParamLocation;

/**
 * Validates the create-relation payload (spec Obj 3). Shape-only: the semantic
 * checks (same-connector, list/detail typing, to_param ∈ detail LLM params,
 * duplicate pair) live in the service as 422s, because they need tenant-scoped
 * DB cross-lookups a FormRequest can't cheaply do.
 */
final class StoreRouteRelationRequest extends FormRequest
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
            'list_route_id' => ['required', 'integer'],
            'detail_route_id' => ['required', 'integer', 'different:list_route_id'],
            'name' => ['nullable', 'string', 'max:128'],
            'description' => ['nullable', 'string'],
            'field_map' => ['required', 'array', 'min:1'],
            'field_map.*.from' => ['required', 'string', 'max:255'],
            'field_map.*.to_param' => ['required', 'string', 'max:128'],
            'field_map.*.to_location' => ['nullable', Rule::in(ParamLocation::values())],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
