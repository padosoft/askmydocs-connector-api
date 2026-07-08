<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Unit;

use Padosoft\AskMyDocsConnectorApi\Support\OutputTransformer;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;

/**
 * Covers the two output-shaping concerns of {@see OutputTransformer}: the byte
 * cap (truncated envelope vs untouched pass-through) and dot-path field
 * selection (include / exclude on concrete paths AND `*` wildcards across
 * collections — the load-bearing case for stripping a sensitive field from
 * every element before the payload reaches the LLM).
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

    public function test_include_wildcard_keeps_only_matched_field_per_element(): void
    {
        $body = ['orders' => [
            ['id' => 1, 'amount' => 9, 'card' => '4111'],
            ['id' => 2, 'amount' => 8, 'card' => '4222'],
        ]];

        $out = $this->transformer->selectFields($body, ['include' => ['orders.*.id']]);

        $this->assertSame(['orders' => [['id' => 1], ['id' => 2]]], $out);
    }

    public function test_include_multiple_wildcards_compose_over_same_collection(): void
    {
        $body = ['orders' => [
            ['id' => 1, 'amount' => 9, 'card' => '4111'],
            ['id' => 2, 'amount' => 8, 'card' => '4222'],
        ]];

        $out = $this->transformer->selectFields($body, [
            'include' => ['orders.*.id', 'orders.*.amount'],
        ]);

        $this->assertSame(['orders' => [
            ['id' => 1, 'amount' => 9],
            ['id' => 2, 'amount' => 8],
        ]], $out);
        // The list shape is preserved (not turned into an assoc map).
        $this->assertArrayHasKey(0, $out['orders']);
    }

    public function test_exclude_wildcard_strips_sensitive_field_from_every_element(): void
    {
        $body = ['orders' => [
            ['id' => 1, 'amount' => 9, 'card' => '4111'],
            ['id' => 2, 'amount' => 8, 'card' => '4222'],
        ]];

        $out = $this->transformer->selectFields($body, ['exclude' => ['orders.*.card']]);

        $this->assertSame(['orders' => [
            ['id' => 1, 'amount' => 9],
            ['id' => 2, 'amount' => 8],
        ]], $out);
        // The sensitive field must be gone from EVERY element — no leak.
        foreach ($out['orders'] as $order) {
            $this->assertArrayNotHasKey('card', $order);
        }
    }

    public function test_exclude_missing_wildcard_path_leaves_body_unchanged(): void
    {
        $body = ['orders' => [['id' => 1], ['id' => 2]]];

        $out = $this->transformer->selectFields($body, ['exclude' => ['orders.*.card']]);

        $this->assertSame($body, $out);
    }
}
