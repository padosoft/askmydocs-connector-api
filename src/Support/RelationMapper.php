<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Support;

use Padosoft\AskMyDocsConnectorApi\Exceptions\ApiConnectorException;

/**
 * Resolves a list→detail relation's `field_map` against a single list item to
 * produce the arguments for the detail route call (spec Obj 3).
 *
 * Two responsibilities:
 *  - {@see itemsAt()} unwraps the item collection from a response body at the
 *    list route's `items_path` ('' / null = the whole body is the array).
 *  - {@see mapArguments()} reads each `from` dot-path out of ONE already-unwrapped
 *    item and binds it to the detail parameter named `to_param`.
 *
 * Load-bearing invariant: `from` paths are relative to a SINGLE item, so the
 * caller MUST unwrap `items_path` (via itemsAt) BEFORE calling mapArguments —
 * never pass the whole envelope. Missing / non-scalar sources throw loudly
 * (R14) rather than binding a silent null into a `{id}` path token.
 */
final class RelationMapper
{
    /**
     * Unwrap the item collection at `$itemsPath` from a decoded response body.
     * '' or null means the whole body IS the array. A path that does not resolve
     * to a list yields an empty collection (nothing to drill).
     *
     * @return list<mixed>
     */
    public function itemsAt(mixed $body, ?string $itemsPath): array
    {
        $path = (string) ($itemsPath ?? '');

        if ($path === '') {
            return is_array($body) && array_is_list($body) ? $body : [];
        }

        $node = $body;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return [];
            }
            $node = $node[$segment];
        }

        return is_array($node) && array_is_list($node) ? $node : [];
    }

    /**
     * Build the detail route's `$arguments` from one list item + a field map.
     *
     * @param  array<string,mixed>  $listItem  a SINGLE item, already unwrapped from items_path
     * @param  array<int,mixed>  $fieldMap  raw JSON-sourced entries (defensively validated per row)
     * @return array<string,mixed> name → value, ready for RequestPlanner (which coerces by type)
     *
     * @throws ApiConnectorException when a `from` path is absent, or resolves to a
     *                               non-scalar (an array/object can't bind to a param)
     */
    public function mapArguments(array $listItem, array $fieldMap): array
    {
        $arguments = [];
        $missing = [];
        $nonScalar = [];

        foreach ($fieldMap as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $from = (string) ($entry['from'] ?? '');
            $toParam = (string) ($entry['to_param'] ?? '');
            if ($from === '' || $toParam === '') {
                continue;
            }

            $resolved = $this->readPath($listItem, $from);
            if (! $resolved['found']) {
                $missing[] = $from;

                continue;
            }
            if (is_array($resolved['value'])) {
                $nonScalar[] = $from;

                continue;
            }

            $arguments[$toParam] = $resolved['value'];
        }

        if ($missing !== []) {
            throw new ApiConnectorException(
                'Cannot build detail arguments: field(s) ['.implode(', ', $missing).'] not found in the selected list item.'
            );
        }

        if ($nonScalar !== []) {
            throw new ApiConnectorException(
                'Cannot build detail arguments: field(s) ['.implode(', ', $nonScalar).'] resolved to a non-scalar value.'
            );
        }

        return $arguments;
    }

    /**
     * Read a dot-path out of a single item, distinguishing "absent" from a
     * present null value (unlike Laravel's data_get).
     *
     * @param  array<string,mixed>  $item
     * @return array{found: bool, value: mixed}
     */
    private function readPath(array $item, string $path): array
    {
        $node = $item;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return ['found' => false, 'value' => null];
            }
            $node = $node[$segment];
        }

        return ['found' => true, 'value' => $node];
    }
}
