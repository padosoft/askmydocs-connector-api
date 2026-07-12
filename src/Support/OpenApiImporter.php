<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Support;

use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorApi\Exceptions\ApiConnectorException;
use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Builds a route configuration from an OpenAPI spec URL (spec: "passa il link
 * OpenAPI e trova tutto da solo"). Authoritative — it reads the contract instead
 * of guessing from a response, and works even when the endpoint needs auth (no
 * live call required).
 *
 * Handles LARGE specs: the whole document is fetched + parsed once, then only the
 * SINGLE operation matching the route (method + path) is extracted — so nothing
 * huge is ever handed to an LLM (no token limit). SSRF-guarded fetch, size-capped.
 * Resolves local `$ref`s. JSON always; YAML when symfony/yaml is present.
 */
final class OpenApiImporter
{
    /** 25 MB — big specs are fine, we only keep one operation. */
    private const MAX_BYTES = 26_214_400;

    private const AUTH_HEADER_NAMES = ['authorization', 'x-api-key', 'api-key', 'apikey', 'x-auth-token', 'token'];

    private const PAGE_NAMES = ['page', 'pagenumber', 'page_number', 'pageindex'];

    private const SIZE_NAMES = ['per_page', 'perpage', 'pagesize', 'page_size', 'limit', 'count', 'size'];

    private const CURSOR_NAMES = ['cursor', 'after', 'next', 'next_cursor', 'page_token', 'pagetoken'];

    public function __construct(private readonly UrlGuard $urlGuard) {}

    /**
     * @return array<string,mixed>|null a config suggestion, or null if the route's operation isn't in the spec
     *
     * @throws RuntimeException on SSRF / fetch / parse failure
     */
    public function configForRoute(string $specUrl, ApiRoute $route): ?array
    {
        $spec = $this->fetchSpec($specUrl);
        $found = $this->findOperation($spec, $route);
        if ($found === null) {
            return null;
        }

        return $this->extractConfig($found['op'], $found['pathItem'], $spec);
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchSpec(string $url): array
    {
        try {
            $this->urlGuard->assertAllowed($url);
        } catch (ApiConnectorException $e) {
            throw new RuntimeException("URL OpenAPI non consentita: {$e->getMessage()}", 422, $e);
        }

        try {
            $response = Http::timeout(30)->get($url);
        } catch (Throwable $e) {
            throw new RuntimeException("Impossibile scaricare l'OpenAPI: {$e->getMessage()}", 422, $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException("Impossibile scaricare l'OpenAPI (HTTP {$response->status()}).", 422);
        }

        $body = $response->body();
        if (strlen($body) > self::MAX_BYTES) {
            throw new RuntimeException('OpenAPI troppo grande (oltre 25 MB).', 422);
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded) && class_exists(Yaml::class)) {
            try {
                $decoded = Yaml::parse($body);
            } catch (Throwable) {
                $decoded = null;
            }
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Il contenuto non è un OpenAPI JSON/YAML valido.', 422);
        }

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $spec
     * @return array{op: array<string,mixed>, pathItem: array<string,mixed>}|null
     */
    private function findOperation(array $spec, ApiRoute $route): ?array
    {
        $method = strtolower($route->http_method->value);
        $routePath = (string) (parse_url($route->url, PHP_URL_PATH) ?: '');
        $paths = is_array($spec['paths'] ?? null) ? $spec['paths'] : [];

        foreach ($this->serverBasePaths($spec) as $base) {
            $stripped = $this->stripPrefix($routePath, $base);
            foreach ($paths as $specPath => $pathItem) {
                if (! is_array($pathItem) || ! is_array($pathItem[$method] ?? null)) {
                    continue;
                }
                if ($this->pathMatches((string) $specPath, $stripped)) {
                    return ['op' => $pathItem[$method], 'pathItem' => $pathItem];
                }
            }
        }

        return null;
    }

    /**
     * Base paths from `servers[].url` (plus '' so a server-less spec still matches),
     * longest first so the most specific base wins.
     *
     * @param  array<string,mixed>  $spec
     * @return list<string>
     */
    private function serverBasePaths(array $spec): array
    {
        $bases = [''];
        foreach (is_array($spec['servers'] ?? null) ? $spec['servers'] : [] as $server) {
            $url = is_array($server) && is_string($server['url'] ?? null) ? $server['url'] : '';
            $path = rtrim((string) (parse_url($url, PHP_URL_PATH) ?: ''), '/');
            if ($path !== '' && ! in_array($path, $bases, true)) {
                $bases[] = $path;
            }
        }
        usort($bases, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $bases;
    }

    private function stripPrefix(string $path, string $base): string
    {
        return $base !== '' && str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /** Segment-wise match; a `{param}` on either side is a wildcard. */
    private function pathMatches(string $specPath, string $routePath): bool
    {
        $a = array_values(array_filter(explode('/', $specPath), static fn (string $s): bool => $s !== ''));
        $b = array_values(array_filter(explode('/', $routePath), static fn (string $s): bool => $s !== ''));
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $i => $seg) {
            $isTemplate = str_starts_with($seg, '{') || str_starts_with($b[$i], '{');
            if (! $isTemplate && $seg !== $b[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $op
     * @param  array<string,mixed>  $pathItem
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    private function extractConfig(array $op, array $pathItem, array $spec): array
    {
        $parameters = $this->extractParameters($op, $pathItem, $spec);
        $parameters = array_merge($parameters, $this->extractBodyParameters($op, $spec));

        $config = [
            'tool_name' => is_string($op['operationId'] ?? null) ? $op['operationId'] : null,
            'tool_description' => is_string($op['summary'] ?? null) && $op['summary'] !== ''
                ? $op['summary']
                : (is_string($op['description'] ?? null) ? $op['description'] : null),
            'parameters' => $parameters,
            'pagination' => $this->detectPagination($parameters),
        ];

        $endpoint = $this->detectEndpointType($op, $spec);
        if ($endpoint !== null) {
            $config['endpoint_type'] = $endpoint['type'];
            $config['items_path'] = $endpoint['items_path'];
        }

        return array_filter($config, static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @param  array<string,mixed>  $op
     * @param  array<string,mixed>  $pathItem
     * @param  array<string,mixed>  $spec
     * @return list<array<string,mixed>>
     */
    private function extractParameters(array $op, array $pathItem, array $spec): array
    {
        $raw = array_merge(
            is_array($pathItem['parameters'] ?? null) ? $pathItem['parameters'] : [],
            is_array($op['parameters'] ?? null) ? $op['parameters'] : [],
        );

        $out = [];
        foreach ($raw as $param) {
            $param = $this->resolveRef($spec, $param);
            if (! is_array($param) || ! is_string($param['name'] ?? null) || $param['name'] === '') {
                continue;
            }
            $in = is_string($param['in'] ?? null) ? $param['in'] : 'query';
            if (! in_array($in, ['query', 'path', 'header'], true)) {
                continue; // cookie / unknown — skip
            }
            $schema = is_array($param['schema'] ?? null) ? $this->resolveRef($spec, $param['schema']) : [];

            $out[$param['name']] = [
                'name' => $param['name'],
                'location' => $in,
                'source' => $in === 'header' && in_array(strtolower($param['name']), self::AUTH_HEADER_NAMES, true) ? 'secret' : 'llm',
                'type' => $this->schemaType(is_array($schema) ? $schema : []),
                'required' => $in === 'path' || (bool) ($param['required'] ?? false),
                'description' => is_string($param['description'] ?? null) ? $param['description'] : null,
            ];
        }

        return array_values($out);
    }

    /**
     * @param  array<string,mixed>  $op
     * @param  array<string,mixed>  $spec
     * @return list<array<string,mixed>>
     */
    private function extractBodyParameters(array $op, array $spec): array
    {
        $body = $this->resolveRef($spec, $op['requestBody'] ?? null);
        $schema = is_array($body) ? $this->resolveRef($spec, $body['content']['application/json']['schema'] ?? null) : null;
        if (! is_array($schema) || ! is_array($schema['properties'] ?? null)) {
            return [];
        }

        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $out = [];
        foreach ($schema['properties'] as $name => $propSchema) {
            $propSchema = $this->resolveRef($spec, $propSchema);
            $out[] = [
                'name' => (string) $name,
                'location' => 'body',
                'source' => 'llm',
                'type' => $this->schemaType(is_array($propSchema) ? $propSchema : []),
                'required' => in_array($name, $required, true),
                'description' => is_array($propSchema) && is_string($propSchema['description'] ?? null) ? $propSchema['description'] : null,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $parameters
     * @return array<string,mixed>|null
     */
    private function detectPagination(array $parameters): ?array
    {
        $names = array_map(static fn (array $p): string => strtolower((string) $p['name']), $parameters);
        $original = [];
        foreach ($parameters as $p) {
            $original[strtolower((string) $p['name'])] = (string) $p['name'];
        }

        foreach (self::CURSOR_NAMES as $cursor) {
            if (in_array($cursor, $names, true)) {
                return ['type' => PaginationType::Cursor->value, 'cursor_param' => $original[$cursor]];
            }
        }
        foreach (self::PAGE_NAMES as $page) {
            if (in_array($page, $names, true)) {
                $size = null;
                foreach (self::SIZE_NAMES as $s) {
                    if (in_array($s, $names, true)) {
                        $size = $original[$s];
                        break;
                    }
                }

                return array_filter([
                    'type' => PaginationType::Page->value,
                    'page_param' => $original[$page],
                    'size_param' => $size,
                    'start_page' => 1,
                ], static fn (mixed $v): bool => $v !== null);
            }
        }

        return null;
    }

    /**
     * List vs detail from the 200 response schema: an array (top-level or under a
     * single array-of-objects property) ⇒ list + items_path.
     *
     * @param  array<string,mixed>  $op
     * @param  array<string,mixed>  $spec
     * @return array{type: string, items_path: string}|null
     */
    private function detectEndpointType(array $op, array $spec): ?array
    {
        $response = $this->resolveRef($spec, $op['responses']['200'] ?? $op['responses']['201'] ?? null);
        $schema = is_array($response) ? $this->resolveRef($spec, $response['content']['application/json']['schema'] ?? null) : null;
        if (! is_array($schema)) {
            return null;
        }

        if (($schema['type'] ?? null) === 'array') {
            return ['type' => EndpointType::List->value, 'items_path' => ''];
        }

        if (($schema['type'] ?? null) === 'object' && is_array($schema['properties'] ?? null)) {
            foreach ($schema['properties'] as $key => $propSchema) {
                $propSchema = $this->resolveRef($spec, $propSchema);
                if (is_array($propSchema) && ($propSchema['type'] ?? null) === 'array') {
                    return ['type' => EndpointType::List->value, 'items_path' => (string) $key];
                }
            }

            return ['type' => EndpointType::Detail->value, 'items_path' => ''];
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $schema
     */
    private function schemaType(array $schema): string
    {
        $type = $schema['type'] ?? null;

        return in_array($type, ['string', 'integer', 'number', 'boolean', 'array', 'object'], true) ? $type : 'string';
    }

    /**
     * Follow a local `$ref` (`#/components/...`) to its target, recursively.
     * Non-refs pass through; cycles are cut at depth 20.
     *
     * @param  array<string,mixed>  $spec
     */
    private function resolveRef(array $spec, mixed $node, int $depth = 0): mixed
    {
        if ($depth > 20 || ! is_array($node)) {
            return $node;
        }
        if (isset($node['$ref']) && is_string($node['$ref'])) {
            return $this->resolveRef($spec, $this->navigate($spec, $node['$ref']), $depth + 1);
        }

        return $node;
    }

    /**
     * @param  array<string,mixed>  $spec
     */
    private function navigate(array $spec, string $ref): mixed
    {
        if (! str_starts_with($ref, '#/')) {
            return null;
        }
        $node = $spec;
        foreach (explode('/', substr($ref, 2)) as $seg) {
            $seg = str_replace(['~1', '~0'], ['/', '~'], $seg);
            if (! is_array($node) || ! array_key_exists($seg, $node)) {
                return null;
            }
            $node = $node[$seg];
        }

        return $node;
    }
}
