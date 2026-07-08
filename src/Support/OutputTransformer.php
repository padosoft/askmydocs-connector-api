<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorApi\Support;

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
            /** @var array<int|string,mixed> $selected */
            $selected = [];
            foreach ($include as $path) {
                $picked = $this->extractPath($body, explode('.', $path));
                if ($picked['matched'] && is_array($picked['value'])) {
                    $selected = $this->mergeDeep($selected, $picked['value']);
                }
            }
            $body = $selected;
        }

        $exclude = $this->stringList($transform['exclude'] ?? null);
        foreach ($exclude as $path) {
            $this->forgetPath($body, explode('.', $path));
        }

        return $body;
    }

    /**
     * Extract the value(s) at a dot-path that may contain `*` wildcards,
     * preserving the surrounding container shape so the result can be merged
     * back into a pruned copy. `*` matches every element/key at that level.
     *
     * @param  list<string>  $segments
     * @return array{matched: bool, value: mixed}
     */
    private function extractPath(mixed $data, array $segments): array
    {
        if ($segments === []) {
            return ['matched' => true, 'value' => $data];
        }

        if (! is_array($data)) {
            return ['matched' => false, 'value' => null];
        }

        $segment = $segments[0];
        $rest = array_slice($segments, 1);

        if ($segment === '*') {
            $isList = array_is_list($data);
            $out = [];
            $matched = false;
            foreach ($data as $key => $value) {
                $res = $this->extractPath($value, $rest);
                if ($res['matched']) {
                    $matched = true;
                    $out[$key] = $res['value'];
                }
            }

            if (! $matched) {
                return ['matched' => false, 'value' => null];
            }

            return ['matched' => true, 'value' => $isList ? array_values($out) : $out];
        }

        if (! array_key_exists($segment, $data)) {
            return ['matched' => false, 'value' => null];
        }

        $res = $this->extractPath($data[$segment], $rest);
        if (! $res['matched']) {
            return ['matched' => false, 'value' => null];
        }

        return ['matched' => true, 'value' => [$segment => $res['value']]];
    }

    /**
     * Recursively merge $b into $a. Array values at the same key are merged
     * (list elements merge by index) so several include paths over the same
     * collection compose into one pruned object.
     *
     * @param  array<int|string,mixed>  $a
     * @param  array<int|string,mixed>  $b
     * @return array<int|string,mixed>
     */
    private function mergeDeep(array $a, array $b): array
    {
        foreach ($b as $key => $value) {
            if (is_array($value) && isset($a[$key]) && is_array($a[$key])) {
                $a[$key] = $this->mergeDeep($a[$key], $value);

                continue;
            }

            $a[$key] = $value;
        }

        return $a;
    }

    /**
     * Remove the value(s) at a dot-path that may contain `*` wildcards. Unlike
     * Arr::forget, this descends through `*`, so an operator can strip a field
     * from every element of a collection (e.g. `orders.*.card`) — the load-
     * bearing case for keeping sensitive fields out of the model's context.
     *
     * @param  list<string>  $segments
     */
    private function forgetPath(mixed &$data, array $segments): void
    {
        if (! is_array($data) || $segments === []) {
            return;
        }

        $segment = $segments[0];
        $rest = array_slice($segments, 1);

        if ($segment === '*') {
            foreach ($data as $key => &$value) {
                if ($rest === []) {
                    unset($data[$key]);

                    continue;
                }

                $this->forgetPath($value, $rest);
            }

            return;
        }

        if ($rest === []) {
            unset($data[$segment]);

            return;
        }

        if (array_key_exists($segment, $data)) {
            $this->forgetPath($data[$segment], $rest);
        }
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
