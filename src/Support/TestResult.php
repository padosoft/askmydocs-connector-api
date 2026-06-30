<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Support;

/**
 * Outcome of a "Test connessione" call (spec §5.2). Distinguishes the three
 * cases explicitly (R14): success+JSON, success+non-JSON/empty, error.
 */
final class TestResult
{
    /**
     * @param  array<string,mixed>  $headers
     * @param  mixed  $body  decoded JSON when $isJson, else raw string
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?int $status,
        public readonly array $headers,
        public readonly mixed $body,
        public readonly bool $isJson,
        public readonly ?string $error = null,
    ) {}

    public static function networkError(string $message): self
    {
        return new self(ok: false, status: null, headers: [], body: null, isJson: false, error: $message);
    }

    /** Short machine label persisted to api_routes.last_test_status. */
    public function statusLabel(): string
    {
        if ($this->error !== null && $this->status === null) {
            return 'network_error';
        }
        if ($this->status === null) {
            return 'unknown';
        }
        if ($this->status >= 200 && $this->status < 300) {
            return $this->isJson ? 'ok' : 'ok_non_json';
        }

        return 'http_'.$this->status;
    }
}
