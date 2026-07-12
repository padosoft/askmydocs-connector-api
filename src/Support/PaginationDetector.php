<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Support;

use Padosoft\AskMyDocsConnectorApi\Models\ApiRoute;

/**
 * Deterministic (no-AI) guess of an endpoint's pagination scheme (spec item 4)
 * from a sample response + the route's own query params/URL. Cursor signals in
 * the body win over page-number signals (they're the stronger evidence). Returns
 * a config array ({@see PaginationType}) or null when nothing is recognised — the
 * service then falls back to the AI, and the operator always confirms/edits.
 */
final class PaginationDetector
{
    /** Query-param names that read as a page NUMBER (incremented by 1). */
    private const PAGE_NAMES = ['page', 'pagenumber', 'page_number', 'pageindex'];

    /** Query-param names that read as a page SIZE. */
    private const SIZE_NAMES = ['per_page', 'perpage', 'pagesize', 'page_size', 'limit', 'count', 'size'];

    /** Body keys that read as a "next cursor / next page" token or URL. */
    private const CURSOR_KEYS = ['next_cursor', 'nextcursor', 'next', 'next_page_token', 'nextpagetoken', 'cursor', 'after'];

    /** Envelope objects a cursor commonly hides under. */
    private const CURSOR_ENVELOPES = ['meta', 'paging', 'pagination', 'page_info', 'pageinfo', 'links', 'cursors'];

    /**
     * @return array<string,mixed>|null
     */
    public function detect(ApiRoute $route, mixed $body): ?array
    {
        $cursor = $this->detectCursor($route, $body);
        if ($cursor !== null) {
            return $cursor;
        }

        return $this->detectPage($route);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function detectCursor(ApiRoute $route, mixed $body): ?array
    {
        if (! is_array($body)) {
            return null;
        }

        $hit = $this->scanForCursor($body, '');
        if ($hit === null) {
            foreach (self::CURSOR_ENVELOPES as $env) {
                if (is_array($body[$env] ?? null)) {
                    $hit = $this->scanForCursor($body[$env], $env);
                    if ($hit !== null) {
                        break;
                    }
                }
            }
        }

        if ($hit === null) {
            return null;
        }

        $config = ['type' => PaginationType::Cursor->value, 'items_path' => $route->items_path];
        if ($hit['is_url']) {
            $config['next_url_path'] = $hit['path'];
        } else {
            $config['cursor_param'] = 'cursor';
            $config['next_cursor_path'] = $hit['path'];
        }

        return array_filter($config, static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @param  array<mixed>  $node
     * @return array{path: string, is_url: bool}|null
     */
    private function scanForCursor(array $node, string $prefix): ?array
    {
        foreach ($node as $key => $value) {
            if (! in_array(strtolower((string) $key), self::CURSOR_KEYS, true)) {
                continue;
            }
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_string($value) && $value !== '') {
                return ['path' => $path, 'is_url' => str_starts_with($value, 'http://') || str_starts_with($value, 'https://')];
            }
            if (is_int($value) || is_float($value)) {
                return ['path' => $path, 'is_url' => false];
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function detectPage(ApiRoute $route): ?array
    {
        $names = $this->queryParamNames($route);

        $pageParam = $this->firstMatch($names, self::PAGE_NAMES);
        if ($pageParam === null) {
            return null;
        }

        return array_filter([
            'type' => PaginationType::Page->value,
            'page_param' => $pageParam,
            'size_param' => $this->firstMatch($names, self::SIZE_NAMES),
            'start_page' => 1,
            'items_path' => $route->items_path,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * The query-param names the route already knows about: declared `query`
     * parameters + any `?a=b` names in the URL.
     *
     * @return list<string>
     */
    private function queryParamNames(ApiRoute $route): array
    {
        $names = [];
        foreach ($route->parameters as $param) {
            if ($param->location === ParamLocation::Query) {
                $names[] = $param->name;
            }
        }

        $query = parse_url($route->url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            parse_str($query, $parsed);
            foreach (array_keys($parsed) as $name) {
                $names[] = (string) $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $candidates
     */
    private function firstMatch(array $names, array $candidates): ?string
    {
        foreach ($names as $name) {
            if (in_array(strtolower($name), $candidates, true)) {
                return $name;
            }
        }

        return null;
    }
}
