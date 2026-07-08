<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Unit;

use Padosoft\AskMyDocsConnectorApi\Exceptions\UrlNotAllowedException;
use Padosoft\AskMyDocsConnectorApi\Support\UrlGuard;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Exercises the STRICT prod SSRF policy deterministically by constructing
 * {@see UrlGuard} directly (NOT fromConfig) with resolveDns:false so no network
 * lookup ever happens. IP literals are validated inline; the allowlist and
 * scheme rules are pure string logic.
 */
final class UrlGuardTest extends TestCase
{
    /** https_only=true must reject a plain http:// URL. */
    public function test_https_only_rejects_http_scheme(): void
    {
        $guard = new UrlGuard(enabled: true, httpsOnly: true, allowlist: [], resolveDns: false);

        $this->expectException(UrlNotAllowedException::class);
        $guard->assertAllowed('http://example.com/data');
    }

    /** A public https host passes (no IP literal, DNS disabled → no lookup). */
    public function test_allows_https_public_host(): void
    {
        $guard = new UrlGuard(enabled: true, httpsOnly: true, allowlist: [], resolveDns: false);

        $guard->assertAllowed('https://api.example.com/v1/orders');

        $this->addToAssertionCount(1);
    }

    /**
     * Private / loopback / link-local / reserved IP literals are always blocked,
     * which covers the cloud-metadata endpoint 169.254.169.254.
     */
    #[DataProvider('blockedIpLiterals')]
    public function test_blocks_private_and_reserved_ip_literals(string $url): void
    {
        $guard = new UrlGuard(enabled: true, httpsOnly: true, allowlist: [], resolveDns: false);

        $this->expectException(UrlNotAllowedException::class);
        $guard->assertAllowed($url);
    }

    /** @return array<string,array{0:string}> */
    public static function blockedIpLiterals(): array
    {
        return [
            'loopback v4' => ['https://127.0.0.1/'],
            'private 10/8' => ['https://10.0.0.1/'],
            'private 192.168/16' => ['https://192.168.1.1/'],
            'link-local metadata' => ['https://169.254.169.254/latest/meta-data/'],
            'loopback v6' => ['https://[::1]/'],
        ];
    }

    /** A public IP literal passes regardless of resolveDns (literal, no lookup). */
    public function test_allows_public_ip_literal(): void
    {
        $guard = new UrlGuard(enabled: true, httpsOnly: true, allowlist: [], resolveDns: false);

        $guard->assertAllowed('https://8.8.8.8/');

        $this->addToAssertionCount(1);
    }

    /** Allowlist matches an exact host and its subdomains. */
    public function test_allowlist_allows_exact_host_and_subdomain(): void
    {
        $guard = new UrlGuard(enabled: true, httpsOnly: true, allowlist: ['api.example.com'], resolveDns: false);

        $guard->assertAllowed('https://api.example.com/v1');
        $guard->assertAllowed('https://sub.api.example.com/v1');

        $this->addToAssertionCount(2);
    }

    /** A host outside a non-empty allowlist is rejected. */
    public function test_allowlist_rejects_unlisted_host(): void
    {
        $guard = new UrlGuard(enabled: true, httpsOnly: true, allowlist: ['api.example.com'], resolveDns: false);

        $this->expectException(UrlNotAllowedException::class);
        $guard->assertAllowed('https://evil.com/');
    }

    /** An empty allowlist permits any public host (still blocks private ranges). */
    public function test_empty_allowlist_allows_any_public_host(): void
    {
        $guard = new UrlGuard(enabled: true, httpsOnly: true, allowlist: [], resolveDns: false);

        $guard->assertAllowed('https://some-random-public-host.example.org/');

        $this->addToAssertionCount(1);
    }

    /** A malformed or hostless URL is rejected before any scheme/IP check. */
    #[DataProvider('malformedUrls')]
    public function test_malformed_or_hostless_url_throws(string $url): void
    {
        $guard = new UrlGuard(enabled: true, httpsOnly: true, allowlist: [], resolveDns: false);

        $this->expectException(UrlNotAllowedException::class);
        $guard->assertAllowed($url);
    }

    /** @return array<string,array{0:string}> */
    public static function malformedUrls(): array
    {
        return [
            'no scheme no host' => ['not-a-url'],
            'empty host' => ['https:///only/a/path'],
            'scheme only' => ['http://'],
        ];
    }

    /** enabled=false short-circuits: not even http://127.0.0.1 throws. */
    public function test_disabled_guard_short_circuits(): void
    {
        $guard = new UrlGuard(enabled: false, httpsOnly: true, allowlist: ['api.example.com'], resolveDns: true);

        $guard->assertAllowed('http://127.0.0.1/');

        $this->addToAssertionCount(1);
    }
}
