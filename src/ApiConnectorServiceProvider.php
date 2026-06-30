<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the AskMyDocs API connector.
 *
 * Registers the package config, its migrations (the 5 `api_*` tables) and —
 * once the services land — the tool registry / executor singletons and the
 * admin HTTP routes (gated by the host-supplied middleware stack, R32).
 *
 * The host application wires the package into its chat tool loop by resolving
 * {@see \Padosoft\AskMyDocsConnectorApi\Services\ApiToolRegistry} +
 * {@see \Padosoft\AskMyDocsConnectorApi\Services\ApiToolExecutor}, and binds the
 * {@see \Padosoft\AskMyDocsConnectorApi\Contracts\ToolDescriptionAssistant}
 * contract to an implementation backed by its AI manager.
 */
class ApiConnectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/connector-api.php',
            'connector-api',
        );

        // Service bindings (UrlGuard, registry, executor, …) are registered in
        // bindServices(); kept separate so the scaffold provider stays readable.
        $this->bindServices();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'api-connector-migrations');

            $this->publishes([
                __DIR__.'/../config/connector-api.php' => config_path('connector-api.php'),
            ], 'api-connector-config');

            $this->registerCommands();
        }
    }

    /**
     * Container bindings for the package services. Filled in as the services
     * land (Task #3 / #4).
     */
    protected function bindServices(): void
    {
        //
    }

    /**
     * Load the admin HTTP routes under the host-configured prefix + middleware.
     * Filled in once the controllers land (Task #4).
     */
    protected function registerRoutes(): void
    {
        //
    }

    /**
     * Register the package's Artisan commands. Filled in Task #9.
     */
    protected function registerCommands(): void
    {
        //
    }
}
