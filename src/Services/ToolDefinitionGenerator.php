<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Services;

use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorApi\Contracts\ToolDescriptionAssistant;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;

/**
 * Produces the public tool definition `{name, description, input_schema}` the
 * LLM sees (spec §5.1 step 6 / §6).
 *
 * Name + description can be LLM-assisted (the host's bound
 * {@see ToolDescriptionAssistant}); otherwise a field-derived draft is used.
 * Everything is a proposal the operator can edit.
 */
final class ToolDefinitionGenerator
{
    public function __construct(private readonly ToolDescriptionAssistant $assistant) {}

    /**
     * @param  array<string,mixed>  $inputSchema  inferred input schema (llm params)
     * @param  mixed  $sampleBody  sample response body for assist context
     * @return array{name: string, description: string, input_schema: array<string,mixed>}
     */
    public function generate(ApiRoute $route, array $inputSchema, mixed $sampleBody = null): array
    {
        $suggestion = $this->assistantSuggestion($route, $inputSchema, $sampleBody);

        $name = $this->normalizeSlug(
            $suggestion['name'] ?? ($route->slug !== '' ? $route->slug : $route->name)
        );

        $description = $suggestion['description']
            ?? ($route->description ?: $this->draftDescription($route));

        return [
            'name' => $name,
            'description' => $description,
            'input_schema' => $inputSchema,
        ];
    }

    /**
     * @param  array<string,mixed>  $inputSchema
     * @return array{name?: string, description?: string}
     */
    private function assistantSuggestion(ApiRoute $route, array $inputSchema, mixed $sampleBody): array
    {
        if (! (bool) config('connector-api.llm_assist.enabled', true)) {
            return [];
        }

        $suggestion = $this->assistant->suggest([
            'method' => $route->http_method->value,
            'url' => $route->url,
            'name' => $route->name,
            'input_schema' => $inputSchema,
            'response_sample' => $this->trimSample($sampleBody),
        ]);

        if (! is_array($suggestion)) {
            return [];
        }

        $out = [];
        if (isset($suggestion['name']) && is_string($suggestion['name']) && $suggestion['name'] !== '') {
            $out['name'] = $suggestion['name'];
        }
        if (isset($suggestion['description']) && is_string($suggestion['description']) && $suggestion['description'] !== '') {
            $out['description'] = $suggestion['description'];
        }

        return $out;
    }

    public function normalizeSlug(string $value): string
    {
        $slug = Str::slug($value, '_');
        $slug = preg_replace('/[^a-z0-9_]/', '', strtolower($slug)) ?? '';
        $slug = trim($slug, '_');
        if ($slug === '' || ! preg_match('/^[a-z_]/', $slug)) {
            $slug = 'tool_'.$slug;
        }

        return substr($slug, 0, 64);
    }

    private function draftDescription(ApiRoute $route): string
    {
        $path = parse_url($route->url, PHP_URL_PATH) ?: $route->url;

        return sprintf(
            'Calls %s %s and returns the live response. Use when the user needs fresh data this endpoint provides.',
            $route->http_method->value,
            $path,
        );
    }

    private function trimSample(mixed $sampleBody): mixed
    {
        if (! is_array($sampleBody)) {
            return $sampleBody;
        }

        // Keep only the first element of any list so the assist prompt stays small.
        if (array_is_list($sampleBody)) {
            return array_slice($sampleBody, 0, 1);
        }

        return $sampleBody;
    }
}
