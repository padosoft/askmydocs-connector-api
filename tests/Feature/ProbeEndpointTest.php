<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;

/**
 * POST /api/admin/api-connectors/probe — the ad-hoc free-endpoint playground.
 * A failed/non-JSON upstream call is a valid display outcome (HTTP 200 with
 * ok:false, R14); only a malformed REQUEST 422s. Nothing is persisted.
 */
final class ProbeEndpointTest extends TestCase
{
    private const URL = '/api/admin/api-connectors/probe';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Standalone-dev: drop the middleware (the host overrides it with its
        // authenticated admin stack anyway — R32).
        $app['config']->set('connector-api.routes.middleware', []);
    }

    public function test_probe_returns_the_live_response_outcome_with_timing(): void
    {
        Http::fake(['*' => Http::response(['orders' => [['id' => 1]]], 200)]);

        $response = $this->postJson(self::URL, [
            'http_method' => 'GET',
            'url' => 'https://api.example.test/orders',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 200)
            ->assertJsonPath('is_json', true)
            ->assertJsonPath('status_label', 'ok')
            ->assertJsonPath('body.orders.0.id', 1);

        $this->assertIsInt($response->json('duration_ms'));
    }

    public function test_probe_surfaces_an_upstream_http_error_at_200(): void
    {
        Http::fake(['*' => Http::response(['message' => 'boom'], 500)]);

        $this->postJson(self::URL, ['http_method' => 'GET', 'url' => 'https://api.example.test/x'])
            ->assertStatus(200) // transport wrapper is 200; the failure lives in the body (R14)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('status', 500)
            ->assertJsonPath('status_label', 'http_500')
            ->assertJsonPath('error', 'Endpoint returned HTTP 500.');
    }

    public function test_probe_validates_method_and_url(): void
    {
        $this->postJson(self::URL, ['http_method' => 'GET'])
            ->assertStatus(422)->assertJsonValidationErrors('url');

        $this->postJson(self::URL, ['http_method' => 'FETCH', 'url' => 'https://x.test'])
            ->assertStatus(422)->assertJsonValidationErrors('http_method');

        $this->postJson(self::URL, ['http_method' => 'GET', 'url' => 'not a url'])
            ->assertStatus(422)->assertJsonValidationErrors('url');
    }

    public function test_probe_forwards_a_json_body_and_headers_for_post(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->postJson(self::URL, [
            'http_method' => 'POST',
            'url' => 'https://api.example.test/orders',
            'headers' => ['X-Api-Note' => 'hi'],
            'body' => ['sku' => 'A1'],
        ])->assertStatus(200);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request['sku'] === 'A1'
            && $request->hasHeader('X-Api-Note', 'hi'));
    }
}
