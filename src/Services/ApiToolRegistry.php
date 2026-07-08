<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Services;

use Illuminate\Database\Eloquent\Builder;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Support\RouteMode;
use Padosoft\AskMyDocsConnectorApi\Support\RouteStatus;

/**
 * Lists the API tools available to a conversation and resolves a tool name back
 * to its executable route (spec §11 ToolRegistry). Tenant-scoped (R30) and
 * project-aware: a route bound to a specific `project_key` only applies to that
 * project; a route with an empty `project_key` is tenant-global.
 *
 * The host merges {@see activeToolsForTenant()} into its chat tool index and,
 * when the LLM calls one, resolves it with {@see routeForTool()} and hands it to
 * {@see ApiToolExecutor}.
 */
final class ApiToolRegistry
{
    /**
     * @return list<array{name: string, definition: array<string,mixed>, route_id: int}>
     */
    public function activeToolsForTenant(string $tenantId, ?string $projectKey = null): array
    {
        $query = $this->baseQuery($tenantId, $projectKey);
        $query->orderBy('id');
        $routes = $query->get();

        $tools = [];
        $seen = [];
        $cap = (int) config('connector-api.tools.max_per_conversation', 16);

        foreach ($routes as $route) {
            $definition = $this->definitionFor($route);
            $name = (string) ($definition['name'] ?? $route->slug);
            if ($name === '' || isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;
            $tools[] = [
                'name' => $name,
                'definition' => $definition,
                'route_id' => $route->id,
            ];

            if ($cap > 0 && count($tools) >= $cap) {
                break;
            }
        }

        return $tools;
    }

    /**
     * Resolve a tool name to its executable route (tenant + project scoped).
     */
    public function routeForTool(string $tenantId, string $slug, ?string $projectKey = null): ?ApiRoute
    {
        return $this->baseQuery($tenantId, $projectKey)
            ->where('slug', $slug)
            ->with('parameters')
            ->first();
    }

    /**
     * @return Builder<ApiRoute>
     */
    private function baseQuery(string $tenantId, ?string $projectKey): Builder
    {
        $projectScopes = $projectKey === null || $projectKey === ''
            ? ['']
            : ['', $projectKey];

        // forTenant() (R30) is the resolvable static head; exposesTool()'s
        // filter is inlined here because a local scope is not resolvable on the
        // Builder mid-chain — mirror ApiRoute::scopeExposesTool() exactly.
        $query = ApiRoute::forTenant($tenantId);
        $query
            ->whereHas('connector', fn ($q) => $q->where('is_active', true))
            ->where('status', RouteStatus::Active->value)
            ->whereIn('mode', [RouteMode::Tool->value, RouteMode::Both->value])
            ->whereIn('project_key', $projectScopes);

        return $query;
    }

    /**
     * @return array<string,mixed>
     */
    private function definitionFor(ApiRoute $route): array
    {
        $definition = $route->tool_definition;
        if (is_array($definition) && isset($definition['name'])) {
            return $definition;
        }

        // Fallback when the cached definition is missing: build a minimal one.
        return [
            'name' => $route->slug,
            'description' => (string) ($route->description ?? $route->name),
            'input_schema' => is_array($route->input_schema)
                ? $route->input_schema
                : ['type' => 'object', 'properties' => [], 'required' => []],
        ];
    }
}
