<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Console;

use Illuminate\Console\Command;
use Padosoft\AskMyDocsConnectorApi\Services\ConnectorAdminService;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;

/**
 * R44 PHP surface — list a tenant's API connectors + routes. Delegates to the
 * SAME core ({@see ConnectorAdminService::listConnectors()}) as the HTTP index
 * and the MCP ApiConnectorsTool. Tenant-scoped (R30) via --tenant.
 */
final class ApiConnectorListCommand extends Command
{
    protected $signature = 'api-connector:list {--tenant=default : Tenant id to scope the listing}';

    protected $description = 'List the API connectors (Connettore API) and their routes for a tenant';

    public function handle(ConnectorAdminService $service, TenantContext $tenants): int
    {
        $tenants->set((string) $this->option('tenant'));

        $connectors = $service->listConnectors();
        if ($connectors->isEmpty()) {
            $this->info("No API connectors for tenant [{$tenants->current()}].");

            return self::SUCCESS;
        }

        foreach ($connectors as $connector) {
            $this->line(sprintf(
                '<info>%s</info> (#%d)%s — %d route(s)',
                $connector->name,
                $connector->id,
                $connector->is_active ? '' : ' [inactive]',
                $connector->routes->count(),
            ));

            $rows = $connector->routes->map(static fn ($route): array => [
                $route->slug,
                $route->http_method->value,
                $route->status->value,
                $route->mode->value,
                $route->last_test_status ?? '—',
            ])->all();

            if ($rows !== []) {
                $this->table(['slug', 'method', 'status', 'mode', 'last_test'], $rows);
            }
        }

        return self::SUCCESS;
    }
}
