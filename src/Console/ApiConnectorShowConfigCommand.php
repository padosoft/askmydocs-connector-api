<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Console;

use Illuminate\Console\Command;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Support\RouteConfig;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;

/**
 * R44 PHP surface for the canonical config JSON — print a route's config
 * ({@see RouteConfig::fromRoute}), the same object the HTTP resource exposes and
 * the FE modal binds to. Tenant-scoped (R30) via --tenant.
 */
final class ApiConnectorShowConfigCommand extends Command
{
    protected $signature = 'api-connector:show-config
        {route : The api_routes id}
        {--tenant=default : Tenant id to scope the route lookup}';

    protected $description = 'Print the canonical config JSON for an API route';

    public function handle(TenantContext $tenants): int
    {
        $tenant = $this->option('tenant');
        $tenants->set(is_string($tenant) ? $tenant : 'default');

        $routeArg = $this->argument('route');
        $routeRef = is_scalar($routeArg) ? (string) $routeArg : '';

        $route = ApiRoute::forTenant($tenants->current())
            ->with('parameters')
            ->find((int) $routeRef);

        if (! $route instanceof ApiRoute) {
            $this->error("Route [{$routeRef}] not found for tenant [{$tenants->current()}].");

            return self::FAILURE;
        }

        $this->line((string) json_encode(
            RouteConfig::fromRoute($route),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return self::SUCCESS;
    }
}
