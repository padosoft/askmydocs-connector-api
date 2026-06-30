<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Padosoft\AskMyDocsConnectorApi\Support\ParamLocation;
use Padosoft\AskMyDocsConnectorApi\Support\ParamSource;
use Padosoft\AskMyDocsConnectorApi\Support\ParamType;

/**
 * Shared validation rules for the `parameters` array on route create/update.
 * Each item carries the two axes (location / source) + type + optional
 * value/secret_ref, mirroring api_route_parameters.
 */
trait ValidatesRouteParameters
{
    /**
     * @param  bool  $required  when true the parameters array itself is required
     * @return array<string,mixed>
     */
    protected function routeParameterRules(bool $required): array
    {
        $arrayRule = $required ? ['required', 'array'] : ['sometimes', 'array'];

        return [
            'parameters' => $arrayRule,
            'parameters.*.name' => ['required_with:parameters', 'string', 'max:128'],
            'parameters.*.location' => ['required_with:parameters', Rule::in(ParamLocation::values())],
            'parameters.*.source' => ['required_with:parameters', Rule::in(ParamSource::values())],
            'parameters.*.type' => ['nullable', Rule::in(ParamType::values())],
            'parameters.*.required' => ['nullable', 'boolean'],
            'parameters.*.value' => ['nullable', 'string'],
            'parameters.*.secret_ref' => ['nullable', 'string', 'max:128'],
            'parameters.*.description' => ['nullable', 'string'],
            'parameters.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
