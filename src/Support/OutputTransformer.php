<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Support;

use Illuminate\Support\Arr;

/**
 * Shapes the API response before it is handed back to the LLM (spec §5.3 / §8):
 *   - field selection — keep only `include` dot-paths, or drop `exclude` ones,
 *     to shrink the payload the LLM must read (dot-paths support `*` wildcards,
 *     e.g. `orders.*.id`);
 *   - byte cap — when the encoded result exceeds the limit it is replaced by a
 *     truncated envelope (`_truncated` + `_note` + `preview`) so the context does
 *     not blow up and mass exfiltration is bounded.
 */
final class OutputTransformer
{
    /**
     * Apply the route's `output_transform` field selection.
     *
     * @param  mixed  $body  decoded response body
     * @param  array<string,mixed>|null  $transform  {include?: list<string>, exclude?: list<string>}
     */
    public function selectFields(mixed $body, ?array $transform): mixed
    {
        if (! is_array($body) || ! is_array($transform)) {
            return $body;
        }

        $include = $this->stringList($transform['include'] ?? null);
        if ($include !== []) {
            $selected = [];
            foreach ($include as $path) {
                $value = data_get($body, $path);
                if ($value !== null) {
                    data_set($selected, $path, $value);
                }
            }
            $body = $selected;
        }

        $exclude = $this->stringList($transform['exclude'] ?? null);
        foreach ($exclude as $path) {
            Arr::forget($body, $path);
        }

        return $body;
    }

    /**
     * Cap the encoded size of a payload. Returns the payload unchanged when it
     * fits, or a truncated envelope when it does not.
     */
    public function capBytes(mixed $payload, int $maxBytes): mixed
    {
        if ($maxBytes <= 0) {
            return $payload;
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) <= $maxBytes) {
            return $payload;
        }

        return [
            '_truncated' => true,
            '_note' => "Output exceeded {$maxBytes} bytes and was truncated; ask a narrower question or add field selection.",
            '_bytes' => strlen($json),
            'preview' => mb_strcut($json, 0, $maxBytes, 'UTF-8'),
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($v): string => is_string($v) ? trim($v) : '', $value),
            static fn (string $v): bool => $v !== '',
        ));
    }
}
