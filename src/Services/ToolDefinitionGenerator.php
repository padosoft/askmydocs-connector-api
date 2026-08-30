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
     * @param  array<string,mixed>  $relationContext  List→Detail relations of THIS route
     *                                                (spec Obj 3 / Fase 3): `inbound` = relations where it is the detail,
     *                                                `outbound` = relations where it is the list — used to annotate the
     *                                                tool + its params so the LLM chains list→detail (concatenation model).
     * @return array{name: string, description: string, input_schema: array<string,mixed>}
     */
    public function generate(ApiRoute $route, array $inputSchema, mixed $sampleBody = null, array $relationContext = []): array
    {
        $suggestion = $this->assistantSuggestion($route, $inputSchema, $sampleBody);

        $name = $this->normalizeSlug(
            $suggestion['name'] ?? ($route->slug !== '' ? $route->slug : $route->name)
        );

        $description = $suggestion['description']
            ?? ($route->description ?: $this->draftDescription($route));

        return $this->annotate([
            'name' => $name,
            'description' => $description,
            'input_schema' => $inputSchema,
        ], $relationContext);
    }

    /**
     * Append relation guidance to the definition AFTER the assist/draft step so
     * the annotations are ALWAYS present (LLM-assist on or off). The detail tool
     * learns which list feeds each param; the list tool advertises its drill-downs.
     *
     * @param  array{name: string, description: string, input_schema: array<string,mixed>}  $definition
     * @param  array<string,mixed>  $relationContext
     * @return array{name: string, description: string, input_schema: array<string,mixed>}
     */
    private function annotate(array $definition, array $relationContext): array
    {
        $inbound = is_array($relationContext['inbound'] ?? null) ? $relationContext['inbound'] : [];
        $outbound = is_array($relationContext['outbound'] ?? null) ? $relationContext['outbound'] : [];

        $properties = is_array($definition['input_schema']['properties'] ?? null)
            ? $definition['input_schema']['properties']
            : [];
        $lines = [];

        // Inbound — THIS route is a DETAIL fed by one or more list tools.
        $listSlugs = [];
        foreach ($inbound as $relation) {
            if (! is_array($relation)) {
                continue;
            }
            $listSlug = (string) ($relation['list_slug'] ?? '');
            if ($listSlug === '') {
                continue;
            }
            $listSlugs[$listSlug] = true;

            $fieldMap = is_array($relation['field_map'] ?? null) ? $relation['field_map'] : [];
            foreach ($fieldMap as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $from = (string) ($entry['from'] ?? '');
                $toParam = (string) ($entry['to_param'] ?? '');
                if ($from === '' || $toParam === '' || ! isset($properties[$toParam]) || ! is_array($properties[$toParam])) {
                    continue;
                }
                $note = sprintf('Typically the `%s` field of an item returned by the `%s` tool.', $from, $listSlug);
                $existing = is_string($properties[$toParam]['description'] ?? null) ? $properties[$toParam]['description'] : '';
                $properties[$toParam]['description'] = trim($existing === '' ? $note : $existing.' '.$note);
            }
        }
        foreach (array_keys($listSlugs) as $slug) {
            $lines[] = sprintf('Call the `%s` tool first to obtain its identifiers.', $slug);
        }

        // Outbound — THIS route is a LIST whose items can be drilled into.
        $detailSlugs = [];
        foreach ($outbound as $relation) {
            if (! is_array($relation)) {
                continue;
            }
            $detailSlug = (string) ($relation['detail_slug'] ?? '');
            if ($detailSlug !== '') {
                $detailSlugs[$detailSlug] = true;
            }
        }
        foreach (array_keys($detailSlugs) as $slug) {
            $lines[] = sprintf('Each returned item can be drilled into with the `%s` tool.', $slug);
        }

        if ($properties !== []) {
            $definition['input_schema']['properties'] = $properties;
        }
        if ($lines !== []) {
            $definition['description'] = trim($definition['description'].' '.implode(' ', $lines));
        }

        return $definition;
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
        if (isset($suggestion['name']) && $suggestion['name'] !== '') {
            $out['name'] = $suggestion['name'];
        }
        if (isset($suggestion['description']) && $suggestion['description'] !== '') {
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
