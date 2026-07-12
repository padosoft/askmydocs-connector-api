<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Support;

/**
 * Deterministically shrinks a large JSON response so its STRUCTURE is readable
 * start-to-end — without an LLM.
 *
 * A raw list endpoint can return hundreds of near-identical objects; the shape
 * is drowned in repetition. This walker keeps the first N items of every array
 * (recursively, so nested arrays inside the kept items are reduced too) and
 * appends a "… +K more" sentinel, preserving every surrounding key so the reader
 * (or a downstream AI analysis) sees the whole structure cheaply. `notes` lists
 * every reduction, sorted by how much was omitted — the group with the most
 * objects first (spec item 3: "riduci il gruppo con più oggetti").
 */
final class StructureReducer
{
    /** Hard depth guard — mirrors {@see SchemaInferrer} so pathological nesting can't blow up. */
    private const MAX_DEPTH = 8;

    public function __construct(private readonly int $defaultSample = 3) {}

    /**
     * @return array{reduced: mixed, notes: list<array{path: string, total: int, kept: int, omitted: int}>}
     */
    public function reduce(mixed $body, ?int $sample = null): array
    {
        $keep = max($sample ?? $this->defaultSample, 1);
        $notes = [];
        $reduced = $this->walk($body, $keep, '', 0, $notes);

        // Largest omitted group first — the "group with the most objects".
        usort($notes, static fn (array $a, array $b): int => $b['omitted'] <=> $a['omitted']);

        return ['reduced' => $reduced, 'notes' => $notes];
    }

    /**
     * @param  list<array{path: string, total: int, kept: int, omitted: int}>  $notes
     */
    private function walk(mixed $value, int $keep, string $path, int $depth, array &$notes): mixed
    {
        if ($depth >= self::MAX_DEPTH || ! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return $this->reduceList($value, $keep, $path, $depth, $notes);
        }

        $out = [];
        foreach ($value as $key => $child) {
            $childPath = $path === '' ? (string) $key : $path.'.'.$key;
            $out[$key] = $this->walk($child, $keep, $childPath, $depth + 1, $notes);
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $list
     * @param  list<array{path: string, total: int, kept: int, omitted: int}>  $notes
     * @return list<mixed>
     */
    private function reduceList(array $list, int $keep, string $path, int $depth, array &$notes): array
    {
        $total = count($list);
        $itemPath = ($path === '' ? '' : $path).'[]';

        $reduced = [];
        foreach (array_slice($list, 0, $keep) as $item) {
            $reduced[] = $this->walk($item, $keep, $itemPath, $depth + 1, $notes);
        }

        if ($total > $keep) {
            $omitted = $total - $keep;
            $notes[] = [
                'path' => $path === '' ? '(root)' : $path,
                'total' => $total,
                'kept' => $keep,
                'omitted' => $omitted,
            ];
            $reduced[] = sprintf('… +%d more (of %d total)', $omitted, $total);
        }

        return $reduced;
    }
}
