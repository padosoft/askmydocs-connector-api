<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Support;

use Padosoft\AskMyDocsConnectorApi\Exceptions\ApiConnectorException;
use Padosoft\AskMyDocsConnectorApi\Models\ApiAuthProfile;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteParameter;

/**
 * Builds a {@see RequestPlan} from a route, its declared parameters and the
 * LLM-supplied arguments (spec §6 param_mapping resolution).
 *
 * Resolution per parameter:
 *   - source=llm    → value from the LLM arguments (validated for required);
 *   - source=fixed  → the configured constant `value`;
 *   - source=secret → looked up from the effective auth profile's credentials
 *                     via `secret_ref` (NEVER exposed to the LLM, NEVER logged).
 *
 * Placement per `location`: path (substitute `{name}` in the URL), query,
 * header, body. Only llm + fixed params are recorded in `loggableParams`.
 */
final class RequestPlanner
{
    /**
     * @param  array<string,mixed>  $arguments  LLM-supplied tool arguments
     */
    public function plan(ApiRoute $route, array $arguments, ?ApiAuthProfile $authProfile): RequestPlan
    {
        $url = $route->url;
        $query = [];
        $headers = [];
        $body = [];
        $loggable = [];
        $missing = [];

        foreach ($route->parameters as $param) {
            $name = $param->name;
            $source = $param->source;
            $location = $param->location;

            [$value, $present] = $this->resolveValue($param, $arguments, $authProfile);

            if (! $present) {
                if ($param->required && $source === ParamSource::Llm) {
                    $missing[] = $name;
                }

                continue;
            }

            $coerced = $this->coerce($value, $param->type);

            if ($source !== ParamSource::Secret) {
                $loggable[$name] = $coerced;
            }

            match ($location) {
                ParamLocation::Path => $url = $this->substitutePath($url, $name, $coerced),
                ParamLocation::Query => $query[$name] = $coerced,
                ParamLocation::Header => $headers[$name] = (string) $coerced,
                ParamLocation::Body => $body[$name] = $coerced,
            };
        }

        if ($missing !== []) {
            throw new ApiConnectorException(
                'Missing required parameter(s): '.implode(', ', $missing).'.'
            );
        }

        $this->assertNoUnresolvedPathTokens($url);

        $method = $route->http_method;
        $bodyPayload = ($body !== [] && $method->allowsBody()) ? $body : null;

        return new RequestPlan(
            method: $method,
            url: $url,
            query: $query,
            headers: $headers,
            body: $bodyPayload,
            loggableParams: $loggable,
        );
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array{0: mixed, 1: bool} [value, present]
     */
    private function resolveValue(ApiRouteParameter $param, array $arguments, ?ApiAuthProfile $authProfile): array
    {
        return match ($param->source) {
            ParamSource::Llm => array_key_exists($param->name, $arguments)
                ? [$arguments[$param->name], true]
                : [null, false],
            ParamSource::Fixed => $param->value !== null
                ? [$param->value, true]
                : [null, false],
            ParamSource::Secret => $this->resolveSecret($param, $authProfile),
        };
    }

    /**
     * @return array{0: mixed, 1: bool}
     */
    private function resolveSecret(ApiRouteParameter $param, ?ApiAuthProfile $authProfile): array
    {
        if ($authProfile === null || ! is_string($param->secret_ref) || $param->secret_ref === '') {
            return [null, false];
        }

        $value = $authProfile->credential($param->secret_ref);

        return $value === null ? [null, false] : [$value, true];
    }

    private function coerce(mixed $value, ParamType $type): mixed
    {
        return match ($type) {
            ParamType::Integer => is_numeric($value) ? (int) $value : $value,
            ParamType::Number => is_numeric($value) ? (float) $value : $value,
            ParamType::Boolean => is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }

    private function substitutePath(string $url, string $name, mixed $value): string
    {
        $token = '{'.$name.'}';
        if (! str_contains($url, $token)) {
            return $url;
        }

        return str_replace($token, rawurlencode((string) $value), $url);
    }

    private function assertNoUnresolvedPathTokens(string $url): void
    {
        if (preg_match('/\{[a-zA-Z0-9_]+\}/', $url, $m) === 1) {
            throw new ApiConnectorException("Unresolved path parameter in URL: {$m[0]}.");
        }
    }
}
