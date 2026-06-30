<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Padosoft\AskMyDocsConnectorApi\Auth\AuthApplierFactory;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Support\HttpDispatcher;
use Padosoft\AskMyDocsConnectorApi\Support\RequestPlanner;
use Padosoft\AskMyDocsConnectorApi\Support\TestResult;
use Padosoft\AskMyDocsConnectorApi\Support\UrlGuard;
use Throwable;

/**
 * Executes the "Test connessione" call (spec §5.1 steps 1–3): a REAL request to
 * the endpoint using the operator's example llm values + the configured
 * fixed/secret params + auth. Persists `last_test_*` on the route and returns a
 * structured {@see TestResult} that distinguishes success / non-JSON / error
 * (R14 — never silent).
 */
final class ApiRouteTester
{
    public function __construct(
        private readonly RequestPlanner $planner,
        private readonly AuthApplierFactory $authFactory,
        private readonly UrlGuard $urlGuard,
        private readonly HttpDispatcher $dispatcher,
    ) {}

    /**
     * @param  array<string,mixed>  $exampleArgs  example values for the llm params
     */
    public function test(ApiRoute $route, array $exampleArgs = []): TestResult
    {
        $result = $this->run($route, $exampleArgs);
        $this->persist($route, $result);

        return $result;
    }

    /**
     * @param  array<string,mixed>  $exampleArgs
     */
    private function run(ApiRoute $route, array $exampleArgs): TestResult
    {
        try {
            $profile = $route->effectiveAuthProfile();
            $plan = $this->planner->plan($route, $exampleArgs, $profile);
            $material = $this->authFactory->materialFor($profile);

            $this->urlGuard->assertAllowed($plan->url);

            $timeoutMs = $route->timeout_ms ?? (int) config('connector-api.defaults.timeout_ms', 10000);
            $response = $this->dispatcher->send($plan, $material, $timeoutMs);

            $raw = $response->body();
            $decoded = json_decode($raw, true);
            $isJson = json_last_error() === JSON_ERROR_NONE && (is_array($decoded));

            return new TestResult(
                ok: $response->successful() && $isJson,
                status: $response->status(),
                headers: $this->flattenHeaders($response->headers()),
                body: $isJson ? $decoded : $raw,
                isJson: $isJson,
                error: $response->successful() ? null : "Endpoint returned HTTP {$response->status()}.",
            );
        } catch (Throwable $e) {
            return TestResult::networkError($e->getMessage());
        }
    }

    private function persist(ApiRoute $route, TestResult $result): void
    {
        $route->forceFill([
            'last_test_at' => $this->now(),
            'last_test_status' => $result->statusLabel(),
            'last_test_payload' => $result->isJson ? $result->body : null,
        ])->save();
    }

    /**
     * @param  array<string, array<int,string>>  $headers
     * @return array<string,string>
     */
    private function flattenHeaders(array $headers): array
    {
        $flat = [];
        foreach ($headers as $name => $values) {
            $flat[$name] = is_array($values) ? implode(', ', $values) : (string) $values;
        }

        return $flat;
    }

    private function now(): Carbon
    {
        return Date::now();
    }
}
