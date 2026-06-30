<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Support;

use Padosoft\AskMyDocsConnectorApi\Exceptions\UrlNotAllowedException;

/**
 * SSRF guard (spec §8). Shared by the test flow and the runtime executor so the
 * same policy is enforced when an operator configures a URL AND every time the
 * tool fires.
 *
 * Policy:
 *   - scheme must be https (configurable down to http for dev);
 *   - the host (or, when DNS resolution is enabled, every resolved A/AAAA
 *     address) must be a PUBLIC address — private / loopback / link-local /
 *     reserved ranges are blocked, which also covers the cloud-metadata
 *     endpoint 169.254.169.254;
 *   - when a non-empty allowlist is configured, the host must match (exactly or
 *     as a subdomain of) one of its entries.
 *
 * AskMyDocs ships no other outbound-URL guard today, so this is the single
 * chokepoint for user-configured endpoints.
 */
final class UrlGuard
{
    /**
     * @param  bool  $enabled        master switch (off only for dev/tests)
     * @param  bool  $httpsOnly      reject non-https schemes
     * @param  list<string>  $allowlist  allowed host suffixes ([] = any public host)
     * @param  bool  $resolveDns     resolve hostnames and check every IP (DNS-rebinding guard)
     */
    public function __construct(
        private readonly bool $enabled = true,
        private readonly bool $httpsOnly = true,
        private readonly array $allowlist = [],
        private readonly bool $resolveDns = true,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            enabled: (bool) config('connector-api.ssrf.enabled', true),
            httpsOnly: (bool) config('connector-api.ssrf.https_only', true),
            allowlist: array_values(array_filter((array) config('connector-api.ssrf.allowlist', []))),
            resolveDns: (bool) config('connector-api.ssrf.resolve_dns', true),
        );
    }

    /**
     * @throws UrlNotAllowedException when the URL violates the SSRF policy.
     */
    public function assertAllowed(string $url): void
    {
        if (! $this->enabled) {
            return;
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host']) || $parts['host'] === '') {
            throw new UrlNotAllowedException("Malformed or hostless URL: '{$url}'.");
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $allowedSchemes = $this->httpsOnly ? ['https'] : ['http', 'https'];
        if (! in_array($scheme, $allowedSchemes, true)) {
            throw new UrlNotAllowedException(
                "URL scheme '{$scheme}' not allowed (permitted: ".implode(', ', $allowedSchemes).').'
            );
        }

        $host = $parts['host'];
        $this->assertHostAllowlisted($host);
        $this->assertHostNotPrivate($host);
    }

    private function assertHostAllowlisted(string $host): void
    {
        if ($this->allowlist === []) {
            return;
        }

        $host = strtolower($host);
        foreach ($this->allowlist as $allowed) {
            $allowed = strtolower(trim((string) $allowed));
            if ($allowed === '') {
                continue;
            }
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return;
            }
        }

        throw new UrlNotAllowedException("Host '{$host}' is not in the domain allowlist.");
    }

    private function assertHostNotPrivate(string $host): void
    {
        // Strip an IPv6 literal's brackets: [::1] → ::1
        $bare = trim($host, '[]');

        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            $this->assertIpPublic($bare, $host);

            return;
        }

        if (! $this->resolveDns) {
            return;
        }

        $ips = $this->resolveHost($bare);
        if ($ips === []) {
            throw new UrlNotAllowedException("Host '{$host}' could not be resolved to a public IP.");
        }

        foreach ($ips as $ip) {
            $this->assertIpPublic($ip, $host);
        }
    }

    private function assertIpPublic(string $ip, string $host): void
    {
        $public = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );

        if ($public === false) {
            throw new UrlNotAllowedException(
                "Host '{$host}' resolves to a private/reserved address ({$ip}); blocked to prevent SSRF."
            );
        }
    }

    /** @return list<string> */
    private function resolveHost(string $host): array
    {
        $ips = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }

        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
