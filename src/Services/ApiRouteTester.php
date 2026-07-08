<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Padosoft\AskMyDocsConnectorApi\Auth\AuthApplierFactory;
use Padosoft\AskMyDocsConnectorApi\Auth\AuthMaterial;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Support\HttpDispatcher;
use Padosoft\AskMyDocsConnectorApi\Support\HttpMethod;
use Padosoft\AskMyDocsConnectorApi\Support\RequestPlan;
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
     * Ad-hoc "playground" probe (spec §5.1 — the FREE-endpoint variant): fire a
     * raw {method, url, headers, query, body} request WITHOUT a persisted route,
     * connector or auth. Runs the SAME execution + response-classification stack
     * as {@see test()} (UrlGuard SSRF → dispatch → JSON classify → TestResult),
     * but persists nothing and infers no schema/tool — it is a read-only
     * diagnostic. The call is unauthenticated by construction (AuthMaterial::none).
     *
     * @param  array<string,string>  $headers
     * @param  array<string,mixed>  $query
     * @param  array<string,mixed>|null  $body  raw JSON body (sent only when the method allows one)
     */
    public function probe(
        HttpMethod $method,
        string $url,
        array $headers = [],
        array $query = [],
        ?array $body = null,
        ?int $timeoutMs = null,
    ): TestResult {
        $plan = new RequestPlan(
            method: $method,
            url: $url,
            query: $query,
            headers: $headers,
            body: $method->allowsBody() ? $body : null,
        );

        $timeout = $timeoutMs ?? (int) config('connector-api.defaults.timeout_ms', 10000);

        return $this->execute($plan, AuthMaterial::none(), $timeout);
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
            $timeoutMs = $route->timeout_ms ?? (int) config('connector-api.defaults.timeout_ms', 10000);
        } catch (Throwable $e) {
            return TestResult::networkError($e->getMessage());
        }

        return $this->execute($plan, $material, $timeoutMs);
    }

    /**
     * Run a resolved plan through UrlGuard + the dispatcher and classify the
     * response into a {@see TestResult} (R14 — success+JSON / success+non-JSON /
     * HTTP error / network error are all distinct), timing the outbound call.
     * Shared by {@see run()} (persisting test) and {@see probe()} (ad-hoc).
     */
    private function execute(RequestPlan $plan, AuthMaterial $material, int $timeoutMs): TestResult
    {
        $startedAt = microtime(true);

        try {
            $this->urlGuard->assertAllowed($plan->url);
            $response = $this->dispatcher->send($plan, $material, $timeoutMs);
            $durationMs = $this->elapsedMs($startedAt);

            $raw = $response->body();
            $decoded = json_decode($raw, true);
            $isJson = json_last_error() === JSON_ERROR_NONE && is_array($decoded);

            return new TestResult(
                ok: $response->successful() && $isJson,
                status: $response->status(),
                headers: $this->flattenHeaders($response->headers()),
                body: $isJson ? $decoded : $raw,
                isJson: $isJson,
                error: $response->successful() ? null : "Endpoint returned HTTP {$response->status()}.",
                durationMs: $durationMs,
            );
        } catch (Throwable $e) {
            return TestResult::networkError($e->getMessage(), $this->elapsedMs($startedAt));
        }
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
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
            $flat[$name] = implode(', ', $values);
        }

        return $flat;
    }

    private function now(): Carbon
    {
        return Date::now();
    }
}
