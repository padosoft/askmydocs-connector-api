<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorApi\Auth\AuthMaterial;
use Padosoft\AskMyDocsConnectorApi\Support\HttpDispatcher;
use Padosoft\AskMyDocsConnectorApi\Support\HttpMethod;
use Padosoft\AskMyDocsConnectorApi\Support\RequestPlan;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The request line has to carry the method exactly as HTTP defines it.
 *
 * RFC 9110 makes the method case-sensitive, and the standard ones are
 * uppercase. Guzzle's PSR-7 Request stores whatever it is handed without
 * normalising, so a lowercased method reaches the wire verbatim as
 * `get /path HTTP/1.1`.
 *
 * Lenient servers accept that, which is why it went unnoticed. Strict ones —
 * PHP's own built-in server among them — answer "Malformed HTTP request" and
 * close the connection without a response. The caller sees cURL 52 "Empty
 * reply from server", an error that points at the network rather than at the
 * request that caused it, and no retry helps.
 */
final class HttpDispatcherMethodCaseTest extends TestCase
{
    /**
     * @return list<array{HttpMethod, string}>
     */
    public static function methods(): array
    {
        return array_map(
            static fn (HttpMethod $method): array => [$method, $method->value],
            HttpMethod::cases(),
        );
    }

    #[DataProvider('methods')]
    public function test_the_method_reaches_the_wire_uppercase(HttpMethod $method, string $expected): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $plan = new RequestPlan(
            method: $method,
            url: 'http://127.0.0.1:8001/healthz',
            query: [],
            headers: [],
            // Mirrors how a plan is really built: the body follows the
            // method's own predicate, so DELETE stays bodyless like in
            // production rather than by a rule invented for the test.
            body: $method->allowsBody() ? ['a' => 1] : null,
        );

        (new HttpDispatcher)->send($plan, AuthMaterial::none(), 5000);

        Http::assertSent(function ($request) use ($expected): bool {
            // Not strtoupper($request->method()) — the point is that the
            // dispatcher already sent it uppercase.
            return $request->method() === $expected
                && $request->method() === strtoupper($request->method());
        });
    }
}
