<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Padosoft\AskMyDocsConnectorApi\Models\ApiAuthProfile;
use Padosoft\AskMyDocsConnectorApi\Models\ApiConnector;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRouteParameter;
use Padosoft\AskMyDocsConnectorApi\Models\ApiToolCallLog;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;
use Padosoft\AskMyDocsConnectorBase\Models\Concerns\BelongsToTenant;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * "The package enforces R30/R31 in its own CI" — the promise from CLAUDE.md.
 *
 * Each of the 5 tenant-aware models MUST (R31): use the connector-base
 * {@see BelongsToTenant} trait (auto-fill tenant_id on create + provide
 * `forTenant()`), AND expose `tenant_id` as mass-assignable. Each of the 5
 * migrations MUST declare a `tenant_id` column.
 */
final class TenantScopingTest extends TestCase
{
    /** @return array<string,array{0:class-string}> */
    public static function tenantAwareModels(): array
    {
        return [
            'ApiConnector' => [ApiConnector::class],
            'ApiAuthProfile' => [ApiAuthProfile::class],
            'ApiRoute' => [ApiRoute::class],
            'ApiRouteParameter' => [ApiRouteParameter::class],
            'ApiToolCallLog' => [ApiToolCallLog::class],
        ];
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('tenantAwareModels')]
    public function test_model_uses_belongs_to_tenant_trait(string $model): void
    {
        $this->assertContains(
            BelongsToTenant::class,
            class_uses_recursive($model),
            "{$model} must use the BelongsToTenant trait (R31).",
        );
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('tenantAwareModels')]
    public function test_model_exposes_tenant_id_as_mass_assignable(string $model): void
    {
        /** @var Model $instance */
        $instance = new $model;

        $this->assertTrue(
            $instance->isFillable('tenant_id'),
            "{$model} must allow mass-assigning tenant_id (in \$fillable, or empty \$guarded) — R31.",
        );
    }

    /** @return array<string,array{0:string}> */
    public static function tenantAwareMigrations(): array
    {
        return [
            'api_connectors' => ['2026_06_30_000001_create_api_connectors_table.php'],
            'api_auth_profiles' => ['2026_06_30_000002_create_api_auth_profiles_table.php'],
            'api_routes' => ['2026_06_30_000003_create_api_routes_table.php'],
            'api_route_parameters' => ['2026_06_30_000004_create_api_route_parameters_table.php'],
            'api_tool_call_logs' => ['2026_06_30_000005_create_api_tool_call_logs_table.php'],
        ];
    }

    #[DataProvider('tenantAwareMigrations')]
    public function test_migration_declares_tenant_id_column(string $file): void
    {
        $path = dirname(__DIR__, 2).'/database/migrations/'.$file;
        $this->assertFileExists($path);

        $source = (string) file_get_contents($path);
        $this->assertStringContainsString(
            "'tenant_id'",
            $source,
            "{$file} must declare a tenant_id column (R31).",
        );
        $this->assertStringContainsString(
            'default',
            $source,
            "{$file} tenant_id should carry a 'default' default (R31).",
        );
    }
}
