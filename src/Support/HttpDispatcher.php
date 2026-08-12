<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorApi\Auth\AuthMaterial;
use Padosoft\AskMyDocsConnectorApi\Services\ApiRouteTester;
use Padosoft\AskMyDocsConnectorApi\Services\ApiToolExecutor;

/**
 * Sends a {@see RequestPlan} (+ {@see AuthMaterial}) over Laravel's HTTP client
 * with the operational policy from spec §9:
 *   - per-call timeout,
 *   - retry-with-backoff ONLY on transient failures (5xx / connection errors),
 *     never on 4xx.
 *
 * Shared by {@see ApiRouteTester} and
 * {@see ApiToolExecutor} so test and
 * runtime behave identically.
 */
final class HttpDispatcher
{
    /**
     * @throws ConnectionException when every transient retry is exhausted.
     */
    public function send(
        RequestPlan $plan,
        AuthMaterial $material,
        int $timeoutMs,
        int $retryTimes = 0,
        int $retryBackoffMs = 0,
    ): Response {
        $headers = array_merge($plan->headers, $material->headers);
        $query = array_merge($plan->query, $material->query);
        $timeoutSeconds = max(1, (int) ceil($timeoutMs / 1000));
        $method = strtolower($plan->method->value);

        $attempt = 0;
        while (true) {
            try {
                $response = $this->sendOnce($method, $plan->url, $headers, $query, $plan->body, $timeoutSeconds);

                // Retry only on 5xx; 2xx/3xx/4xx are terminal (no retry on 4xx).
                if ($response->status() < 500 || $attempt >= $retryTimes) {
                    return $response;
                }
            } catch (ConnectionException $e) {
                if ($attempt >= $retryTimes) {
                    throw $e;
                }
            }

            $attempt++;
            if ($retryBackoffMs > 0) {
                usleep($retryBackoffMs * 1000 * $attempt);
            }
        }
    }

    /**
     * @param  array<string,string>  $headers
     * @param  array<string,mixed>  $query
     * @param  array<string,mixed>|null  $body
     */
    private function sendOnce(
        string $method,
        string $url,
        array $headers,
        array $query,
        ?array $body,
        int $timeoutSeconds,
    ): Response {
        $request = Http::withHeaders($headers)
            ->withOptions(['allow_redirects' => false])
            ->timeout($timeoutSeconds)
            ->acceptJson();

        $options = [];
        if ($query !== []) {
            $options['query'] = $query;
        }
        if ($body !== null) {
            $options['json'] = $body;
        }

        return $request->send($method, $url, $options);
    }
}
