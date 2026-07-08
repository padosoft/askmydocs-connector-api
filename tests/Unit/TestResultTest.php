<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Unit;

use Padosoft\AskMyDocsConnectorApi\Support\TestResult;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;

/**
 * {@see TestResult::statusLabel()} is persisted to `api_routes.last_test_status`
 * and drives the UI's success/non-JSON/error distinction (R14). Lock every
 * branch of the label so a caller can always tell the three outcomes apart.
 */
final class TestResultTest extends TestCase
{
    public function test_network_error_factory(): void
    {
        $result = TestResult::networkError('connection refused');

        $this->assertFalse($result->ok);
        $this->assertNull($result->status);
        $this->assertSame('connection refused', $result->error);
        $this->assertFalse($result->isJson);
        $this->assertSame('network_error', $result->statusLabel());
    }

    public function test_success_json_label_is_ok(): void
    {
        $result = new TestResult(
            ok: true,
            status: 200,
            headers: [],
            body: ['a' => 1],
            isJson: true,
        );

        $this->assertSame('ok', $result->statusLabel());
    }

    public function test_success_non_json_label(): void
    {
        $result = new TestResult(
            ok: false,
            status: 204,
            headers: [],
            body: '',
            isJson: false,
        );

        $this->assertSame('ok_non_json', $result->statusLabel());
    }

    public function test_http_error_label_carries_the_status(): void
    {
        $result = new TestResult(
            ok: false,
            status: 404,
            headers: [],
            body: 'not found',
            isJson: false,
            error: 'Endpoint returned HTTP 404.',
        );

        $this->assertSame('http_404', $result->statusLabel());
    }

    public function test_unknown_label_when_no_status_and_no_error(): void
    {
        $result = new TestResult(
            ok: false,
            status: null,
            headers: [],
            body: null,
            isJson: false,
            error: null,
        );

        $this->assertSame('unknown', $result->statusLabel());
    }
}
