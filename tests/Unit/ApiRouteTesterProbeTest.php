<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorApi\Services\ApiRouteTester;
use Padosoft\AskMyDocsConnectorApi\Support\HttpMethod;
use Padosoft\AskMyDocsConnectorApi\Support\TestResult;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;

/**
 * ApiRouteTester::probe() — the ad-hoc, unauthenticated, NON-persisting live
 * call behind the free-endpoint playground. Same response classification as the
 * persisting test() path (R14), plus timing, minus any auth/persistence.
 */
final class ApiRouteTesterProbeTest extends TestCase
{
    private function tester(): ApiRouteTester
    {
        return $this->app->make(ApiRouteTester::class);
    }

    public function test_probe_classifies_a_json_success_with_timing(): void
    {
        Http::fake(['*' => Http::response(['orders' => [['id' => 1]]], 200)]);

        $result = $this->tester()->probe(HttpMethod::GET, 'https://api.example.test/orders');

        $this->assertInstanceOf(TestResult::class, $result);
        $this->assertTrue($result->ok);
        $this->assertSame(200, $result->status);
        $this->assertTrue($result->isJson);
        $this->assertSame(['orders' => [['id' => 1]]], $result->body);
        $this->assertNull($result->error);
        $this->assertIsInt($result->durationMs);
        $this->assertGreaterThanOrEqual(0, $result->durationMs);
    }

    public function test_probe_marks_an_http_error_as_not_ok(): void
    {
        Http::fake(['*' => Http::response(['message' => 'nope'], 404)]);

        $result = $this->tester()->probe(HttpMethod::GET, 'https://api.example.test/missing');

        $this->assertFalse($result->ok);
        $this->assertSame(404, $result->status);
        $this->assertSame('http_404', $result->statusLabel());
        $this->assertNotNull($result->error);
    }

    public function test_probe_flags_a_non_json_body(): void
    {
        Http::fake(['*' => Http::response('<html>hi</html>', 200, ['Content-Type' => 'text/html'])]);

        $result = $this->tester()->probe(HttpMethod::GET, 'https://api.example.test/page');

        $this->assertFalse($result->ok);
        $this->assertFalse($result->isJson);
        $this->assertSame('<html>hi</html>', $result->body);
        $this->assertSame(200, $result->status);
    }

    public function test_probe_sends_a_json_body_for_post_with_headers_and_no_auth(): void
    {
        Http::fake(['*' => Http::response(['created' => true], 201)]);

        $this->tester()->probe(
            HttpMethod::POST,
            'https://api.example.test/orders',
            ['X-Custom' => 'v'],
            [],
            ['sku' => 'A1', 'qty' => 2],
        );

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.example.test/orders'
                && $request['sku'] === 'A1'
                && $request->hasHeader('X-Custom', 'v')
                // unauthenticated by construction — no auth material is ever added.
                && ! $request->hasHeader('Authorization');
        });
    }

    public function test_probe_never_sends_a_body_on_a_get(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->tester()->probe(HttpMethod::GET, 'https://api.example.test/x', [], [], ['ignored' => true]);

        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->data() === []);
    }
}
