<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;
use Padosoft\AskMyDocsConnectorApi\Auth\AuthApplierFactory;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use Padosoft\AskMyDocsConnectorApi\Models\ApiToolCallLog;
use Padosoft\AskMyDocsConnectorApi\Support\HttpDispatcher;
use Padosoft\AskMyDocsConnectorApi\Support\OutputTransformer;
use Padosoft\AskMyDocsConnectorApi\Support\RequestPlan;
use Padosoft\AskMyDocsConnectorApi\Support\RequestPlanner;
use Padosoft\AskMyDocsConnectorApi\Support\UrlGuard;
use Throwable;

/**
 * Runtime executor (spec §7.2). Given a route + the LLM-supplied arguments it:
 * resolves the binding (path/query/header/body + auth), guards the URL (SSRF),
 * sends the request with timeout/retry, applies per-route rate-limit + short-TTL
 * cache, transforms + byte-caps the output, writes a sanitised
 * api_tool_call_logs row and returns the JSON `tool_result`.
 *
 * Execution is 100% server-side: the LLM only ever supplies the `llm` argument
 * values and only ever receives the (transformed, capped) response. URLs,
 * headers and secrets never leave the server. On any failure a structured
 * `{error, status}` is returned so the model can explain it rather than fail
 * silently (spec §9, R14).
 */
final class ApiToolExecutor
{
    public function __construct(
        private readonly RequestPlanner $planner,
        private readonly AuthApplierFactory $authFactory,
        private readonly UrlGuard $urlGuard,
        private readonly HttpDispatcher $dispatcher,
        private readonly OutputTransformer $transformer,
    ) {}

    /**
     * @param  array<string,mixed>  $arguments  LLM-supplied tool arguments
     * @param  array<string,mixed>  $context  {conversation_id?: int}
     * @return array<string,mixed> the tool_result handed back to the LLM
     */
    public function execute(ApiRoute $route, array $arguments, array $context = []): array
    {
        if ($this->isRateLimited($route)) {
            return ['error' => 'Rate limit exceeded for this tool. Try again shortly.', 'status' => 429];
        }

        $cacheKey = $this->cacheKey($route, $arguments);
        if ($cacheKey !== null) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $startedAt = microtime(true);
        $result = $this->run($route, $arguments, $context, $startedAt);

        if ($cacheKey !== null && ! isset($result['error'])) {
            Cache::put($cacheKey, $result, (int) $route->cache_ttl_s);
        }

        return $result;
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function run(ApiRoute $route, array $arguments, array $context, float $startedAt): array
    {
        $plan = null;

        try {
            $profile = $route->effectiveAuthProfile();
            $plan = $this->planner->plan($route, $arguments, $profile);
            $material = $this->authFactory->materialFor($profile);

            $this->urlGuard->assertAllowed($plan->url);

            $timeoutMs = $route->timeout_ms ?? (int) config('connector-api.defaults.timeout_ms', 10000);
            $retryTimes = (int) config('connector-api.defaults.retry_times', 2);
            $retryBackoffMs = (int) config('connector-api.defaults.retry_backoff_ms', 250);

            $response = $this->dispatcher->send($plan, $material, $timeoutMs, $retryTimes, $retryBackoffMs);
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            $decoded = json_decode($response->body(), true);
            $isJson = json_last_error() === JSON_ERROR_NONE && is_array($decoded);

            if (! $response->successful()) {
                $error = "Endpoint returned HTTP {$response->status()}.";
                $this->log($route, $context, $plan, $response->status(), $isJson ? $decoded : null, $latencyMs, $error);

                return ['error' => $error, 'status' => $response->status()];
            }

            if (! $isJson) {
                $error = 'Endpoint returned a non-JSON response (unsupported in Fase 1).';
                $this->log($route, $context, $plan, $response->status(), null, $latencyMs, $error);

                return ['error' => $error, 'status' => $response->status()];
            }

            $shaped = $this->transformer->selectFields($decoded, $route->output_transform);
            $capped = $this->transformer->capBytes($shaped, (int) config('connector-api.output.max_bytes', 16384));

            $this->log($route, $context, $plan, $response->status(), $capped, $latencyMs, null);

            return $this->wrap($capped);
        } catch (Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->log($route, $context, $plan, null, null, $latencyMs, $e->getMessage());

            return ['error' => $e->getMessage(), 'status' => null];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function wrap(mixed $body): array
    {
        if (is_array($body)) {
            return $body;
        }

        return ['result' => $body];
    }

    private function isRateLimited(ApiRoute $route): bool
    {
        $limit = (int) ($route->rate_limit ?? 0);
        if ($limit <= 0) {
            return false;
        }

        $key = 'api-connector:rl:'.$route->tenant_id.':'.$route->id;
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return true;
        }

        RateLimiter::hit($key, 60);

        return false;
    }

    /**
     * @param  array<string,mixed>  $arguments
     */
    private function cacheKey(ApiRoute $route, array $arguments): ?string
    {
        $ttl = (int) ($route->cache_ttl_s ?? 0);
        if ($ttl <= 0) {
            return null;
        }

        ksort($arguments);
        $hash = md5((string) json_encode($arguments));

        return 'api-connector:cache:'.$route->tenant_id.':'.$route->id.':'.$hash;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function log(
        ApiRoute $route,
        array $context,
        ?RequestPlan $plan,
        ?int $status,
        mixed $excerpt,
        int $latencyMs,
        ?string $error,
    ): void {
        try {
            ApiToolCallLog::query()->create([
                'tenant_id' => $route->tenant_id,
                'conversation_id' => isset($context['conversation_id']) ? (int) $context['conversation_id'] : null,
                'api_route_id' => $route->id,
                'request_params' => $plan->loggableParams ?? [],
                'response_status' => $status,
                'response_excerpt' => is_array($excerpt) ? $excerpt : null,
                'latency_ms' => $latencyMs,
                'error' => $error,
                'created_at' => Date::now(),
            ]);
        } catch (Throwable) {
            // Logging must never break the tool path (mirrors ChatLogManager).
        }
    }
}
