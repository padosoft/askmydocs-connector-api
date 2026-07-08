<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Unit;

use Padosoft\AskMyDocsConnectorApi\Support\OutputTransformer;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;

/**
 * Covers the two output-shaping concerns of {@see OutputTransformer}: the byte
 * cap (truncated envelope vs untouched pass-through) and dot-path field
 * selection (include / exclude on concrete paths).
 */
final class OutputTransformerTest extends TestCase
{
    private OutputTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new OutputTransformer;
    }

    public function test_cap_bytes_truncates_oversized_payload_with_explanatory_note(): void
    {
        $payload = ['blob' => str_repeat('x', 500)];

        $capped = $this->transformer->capBytes($payload, 100);

        $this->assertIsArray($capped);
        $this->assertTrue($capped['_truncated']);
        $this->assertStringContainsString('truncated', $capped['_note']);
        $this->assertStringContainsString('100 bytes', $capped['_note']);
        $this->assertArrayHasKey('_bytes', $capped);
        $this->assertGreaterThan(100, $capped['_bytes']);
        $this->assertArrayHasKey('preview', $capped);
        // The preview must be clamped to the byte cap.
        $this->assertLessThanOrEqual(100, strlen($capped['preview']));
        // The original data must NOT survive verbatim.
        $this->assertArrayNotHasKey('blob', $capped);
    }

    public function test_cap_bytes_leaves_small_payload_untouched(): void
    {
        $payload = ['ok' => true, 'n' => 3];

        $this->assertSame($payload, $this->transformer->capBytes($payload, 16384));
    }

    public function test_cap_bytes_disabled_when_max_is_zero_or_negative(): void
    {
        $payload = ['blob' => str_repeat('x', 5000)];

        $this->assertSame($payload, $this->transformer->capBytes($payload, 0));
        $this->assertSame($payload, $this->transformer->capBytes($payload, -1));
    }

    public function test_select_fields_keeps_only_included_paths(): void
    {
        $body = [
            'id' => 42,
            'secret' => 'nope',
            'user' => ['name' => 'Ada', 'ssn' => '000'],
        ];

        $out = $this->transformer->selectFields($body, ['include' => ['id', 'user.name']]);

        $this->assertSame(['id' => 42, 'user' => ['name' => 'Ada']], $out);
    }

    public function test_select_fields_drops_excluded_paths(): void
    {
        $body = ['id' => 1, 'password' => 'x', 'meta' => ['token' => 't', 'ok' => true]];

        $out = $this->transformer->selectFields($body, ['exclude' => ['password', 'meta.token']]);

        $this->assertSame(['id' => 1, 'meta' => ['ok' => true]], $out);
    }

    public function test_select_fields_returns_body_unchanged_when_transform_is_null(): void
    {
        $body = ['a' => 1];

        $this->assertSame($body, $this->transformer->selectFields($body, null));
    }

    public function test_select_fields_returns_scalar_body_unchanged(): void
    {
        $this->assertSame('plain', $this->transformer->selectFields('plain', ['include' => ['x']]));
    }
}
