<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Tests\Unit;

use Padosoft\AskMyDocsConnectorApi\Support\StructureReducer;
use Padosoft\AskMyDocsConnectorApi\Tests\TestCase;

/**
 * {@see StructureReducer} shrinks a large response so its STRUCTURE reads
 * start-to-end: every array is truncated to N items + a "… +K more" sentinel,
 * recursively, keeping all surrounding keys; `notes` reports each reduction with
 * the biggest omitted group first.
 */
final class StructureReducerTest extends TestCase
{
    public function test_it_leaves_a_small_response_untouched(): void
    {
        $body = ['id' => 1, 'items' => [['a' => 1], ['a' => 2]]];

        $out = (new StructureReducer)->reduce($body);

        $this->assertSame($body, $out['reduced']);
        $this->assertSame([], $out['notes']);
    }

    public function test_it_truncates_a_long_array_of_objects_and_appends_a_sentinel(): void
    {
        $items = [];
        for ($i = 1; $i <= 100; $i++) {
            $items[] = ['id' => $i, 'name' => "n{$i}"];
        }

        $out = (new StructureReducer)->reduce(['data' => $items], sample: 3);

        // Surrounding structure kept; the collection truncated to 3 + sentinel.
        $this->assertCount(4, $out['reduced']['data']);
        $this->assertSame(['id' => 1, 'name' => 'n1'], $out['reduced']['data'][0]);
        $this->assertSame('… +97 more (of 100 total)', $out['reduced']['data'][3]);
        // The note records the reduction under its dot-path.
        $this->assertSame([
            ['path' => 'data', 'total' => 100, 'kept' => 3, 'omitted' => 97],
        ], $out['notes']);
    }

    public function test_it_reduces_nested_arrays_inside_kept_items(): void
    {
        $body = [
            'orders' => [
                ['id' => 1, 'lines' => array_fill(0, 10, ['sku' => 'x'])],
                ['id' => 2, 'lines' => array_fill(0, 10, ['sku' => 'y'])],
            ],
        ];

        $out = (new StructureReducer)->reduce($body, sample: 1);

        // Outer array kept 1 order + sentinel; the kept order's `lines` also reduced.
        $this->assertCount(2, $out['reduced']['orders']);          // 1 order + sentinel
        $this->assertCount(2, $out['reduced']['orders'][0]['lines']); // 1 line + sentinel
        $this->assertSame('… +9 more (of 10 total)', $out['reduced']['orders'][0]['lines'][1]);
    }

    public function test_notes_are_sorted_by_the_largest_omitted_group_first(): void
    {
        $body = [
            'small' => array_fill(0, 5, ['a' => 1]),   // omits 2 (sample 3)
            'big' => array_fill(0, 50, ['b' => 1]),    // omits 47
        ];

        $out = (new StructureReducer)->reduce($body, sample: 3);

        $this->assertSame('big', $out['notes'][0]['path']);
        $this->assertSame(47, $out['notes'][0]['omitted']);
        $this->assertSame('small', $out['notes'][1]['path']);
    }

    public function test_it_truncates_a_top_level_list_and_a_scalar_list(): void
    {
        $topLevel = (new StructureReducer)->reduce(array_fill(0, 20, ['id' => 1]), sample: 2);
        $this->assertSame('(root)', $topLevel['notes'][0]['path']);
        $this->assertCount(3, $topLevel['reduced']); // 2 + sentinel

        $scalars = (new StructureReducer)->reduce(['ids' => range(1, 30)], sample: 2);
        $this->assertSame([1, 2, '… +28 more (of 30 total)'], $scalars['reduced']['ids']);
    }
}
