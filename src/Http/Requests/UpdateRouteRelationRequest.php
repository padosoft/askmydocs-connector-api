<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Padosoft\AskMyDocsConnectorApi\Support\ParamLocation;

/**
 * Validates the update-relation payload. All fields optional (PATCH); when
 * `field_map` is present it fully replaces the map. Semantic checks stay in the
 * service (422).
 */
final class UpdateRouteRelationRequest extends FormRequest
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
            'list_route_id' => ['sometimes', 'integer'],
            'detail_route_id' => ['sometimes', 'integer', 'different:list_route_id'],
            'name' => ['sometimes', 'nullable', 'string', 'max:128'],
            'description' => ['sometimes', 'nullable', 'string'],
            'field_map' => ['sometimes', 'array', 'min:1'],
            'field_map.*.from' => ['required_with:field_map', 'string', 'max:255'],
            'field_map.*.to_param' => ['required_with:field_map', 'string', 'max:128'],
            'field_map.*.to_location' => ['nullable', Rule::in(ParamLocation::values())],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
