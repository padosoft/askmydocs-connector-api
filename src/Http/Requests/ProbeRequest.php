<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Padosoft\AskMyDocsConnectorApi\Support\HttpMethod;

/**
 * Validates an ad-hoc "playground" probe (the FREE-endpoint modal): a raw
 * { http_method, url, headers, query, body } live call with NO auth and NO
 * persistence. `url` must be a syntactically valid URL; the https-only + SSRF
 * policy is still enforced by UrlGuard at send time (a http:// URL is rejected
 * there, surfaced as a network_error TestResult — not a 422 here).
 *
 * Accessors are deliberately NOT named url()/query()/method()/headers() so they
 * do not shadow the framework's own Request methods.
 */
final class ProbeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'http_method' => ['required', 'string', Rule::in(HttpMethod::values())],
            'url' => ['required', 'string', 'url', 'max:2048'],
            'headers' => ['nullable', 'array'],
            'headers.*' => ['string'],
            'query' => ['nullable', 'array'],
            'query.*' => ['string'],
            'body' => ['nullable', 'array'],
        ];
    }

    public function httpMethod(): HttpMethod
    {
        return HttpMethod::from((string) $this->validated('http_method'));
    }

    public function targetUrl(): string
    {
        return (string) $this->validated('url');
    }

    /**
     * @return array<string,string>
     */
    public function headerMap(): array
    {
        return $this->stringMap('headers');
    }

    /**
     * @return array<string,string>
     */
    public function queryParams(): array
    {
        return $this->stringMap('query');
    }

    /**
     * @return array<string,mixed>|null
     */
    public function jsonBody(): ?array
    {
        $body = $this->validated('body');

        return is_array($body) ? $body : null;
    }

    /**
     * @return array<string,string>
     */
    private function stringMap(string $key): array
    {
        $value = $this->validated($key, []);
        if (! is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $k => $v) {
            if (is_string($v)) {
                $map[(string) $k] = $v;
            }
        }

        return $map;
    }
}
