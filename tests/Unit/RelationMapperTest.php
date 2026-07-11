<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Unit;

use Padosoft\AskMyDocsConnectorApi\Exceptions\ApiConnectorException;
use Padosoft\AskMyDocsConnectorApi\Support\RelationMapper;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;

/**
 * {@see RelationMapper} unwraps the item collection at items_path and binds a
 * single item's fields onto the detail route arguments. Missing / non-scalar
 * sources throw loudly (R14) — never a silent null in a `{id}` token.
 */
final class RelationMapperTest extends TestCase
{
    private RelationMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new RelationMapper;
    }

    public function test_items_at_top_level_array(): void
    {
        $items = $this->mapper->itemsAt([['id' => 1], ['id' => 2]], '');
        $this->assertCount(2, $items);
        $this->assertSame(1, $items[0]['id']);
    }

    public function test_items_at_envelope_key(): void
    {
        $items = $this->mapper->itemsAt(['data' => [['id' => 9]], 'meta' => []], 'data');
        $this->assertCount(1, $items);
        $this->assertSame(9, $items[0]['id']);
    }

    public function test_items_at_nested_dot_path(): void
    {
        $items = $this->mapper->itemsAt(['result' => ['orders' => [['id' => 3]]]], 'result.orders');
        $this->assertSame(3, $items[0]['id']);
    }

    public function test_items_at_non_list_yields_empty(): void
    {
        $this->assertSame([], $this->mapper->itemsAt(['data' => ['id' => 1]], 'data'));
        $this->assertSame([], $this->mapper->itemsAt('nope', ''));
        $this->assertSame([], $this->mapper->itemsAt(['a' => 1], 'missing'));
    }

    public function test_map_arguments_binds_scalar_fields(): void
    {
        $args = $this->mapper->mapArguments(
            ['id' => 42, 'customer' => ['code' => 'AB']],
            [
                ['from' => 'id', 'to_param' => 'id'],
                ['from' => 'customer.code', 'to_param' => 'customer'],
            ],
        );

        $this->assertSame(['id' => 42, 'customer' => 'AB'], $args);
    }

    public function test_map_arguments_preserves_a_present_null(): void
    {
        // A present null is a valid scalar value — not "missing".
        $args = $this->mapper->mapArguments(['id' => null], [['from' => 'id', 'to_param' => 'id']]);
        $this->assertArrayHasKey('id', $args);
        $this->assertNull($args['id']);
    }

    public function test_map_arguments_throws_on_missing_field(): void
    {
        $this->expectException(ApiConnectorException::class);
        $this->expectExceptionMessageMatches('/not found/');
        $this->mapper->mapArguments(['name' => 'x'], [['from' => 'id', 'to_param' => 'id']]);
    }

    public function test_map_arguments_throws_on_non_scalar_source(): void
    {
        $this->expectException(ApiConnectorException::class);
        $this->expectExceptionMessageMatches('/non-scalar/');
        $this->mapper->mapArguments(
            ['customer' => ['code' => 'AB']],
            [['from' => 'customer', 'to_param' => 'customer']],
        );
    }
}
