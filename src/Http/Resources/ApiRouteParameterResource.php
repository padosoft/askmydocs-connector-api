<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Padosoft\AskMyDocsConnectorApi\Models\ApiAuthProfile;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteParameter;

/**
 * Public shape of a single route parameter. `secret_ref` is the NAME of the
 * credential key (a reference, not the secret itself) so the operator can see
 * the binding; the credential VALUE never leaves {@see ApiAuthProfile}.
 *
 * @mixin ApiRouteParameter
 */
final class ApiRouteParameterResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'location' => $this->location->value,
            'source' => $this->source->value,
            'type' => $this->type->value,
            'required' => $this->required,
            'value' => $this->value,
            'secret_ref' => $this->secret_ref,
            'description' => $this->description,
            'sort_order' => $this->sort_order,
        ];
    }
}
