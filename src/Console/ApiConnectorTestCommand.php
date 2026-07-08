<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Console;

use Illuminate\Console\Command;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Services\ApiRouteTester;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;

/**
 * R44 PHP surface — run a route's "Test connessione" from the CLI. Delegates to
 * the SAME core ({@see ApiRouteTester}) as the HTTP `routes/{id}/test` endpoint.
 * Tenant-scoped (R30) via --tenant; example llm args via --args (JSON).
 */
final class ApiConnectorTestCommand extends Command
{
    protected $signature = 'api-connector:test
        {route : The api_routes id to test}
        {--tenant=default : Tenant id to scope the route lookup}
        {--args= : JSON object of example values for the llm parameters}';

    protected $description = 'Execute a real test call for an API route and show the result';

    public function handle(ApiRouteTester $tester, TenantContext $tenants): int
    {
        $tenant = $this->option('tenant');
        $tenants->set(is_string($tenant) ? $tenant : 'default');

        $routeArg = $this->argument('route');
        $routeRef = is_scalar($routeArg) ? (string) $routeArg : '';

        $route = ApiRoute::forTenant($tenants->current())
            ->with('parameters')
            ->find((int) $routeRef);

        if ($route === null) {
            $this->error("Route [{$routeRef}] not found for tenant [{$tenants->current()}].");

            return self::FAILURE;
        }

        $exampleArgs = $this->decodeArgs();
        if ($exampleArgs === null) {
            $this->error('--args must be a valid JSON object.');

            return self::FAILURE;
        }

        $result = $tester->test($route, $exampleArgs);

        $this->line('Status:  '.($result->status ?? 'n/a').' ('.$result->statusLabel().')');
        $this->line('JSON:    '.($result->isJson ? 'yes' : 'no'));
        if ($result->error !== null) {
            $this->warn('Error:   '.$result->error);
        }
        $this->line('Body:');
        $this->line((string) json_encode($result->body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $result->ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>|null null on invalid JSON
     */
    private function decodeArgs(): ?array
    {
        $raw = $this->option('args');
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
