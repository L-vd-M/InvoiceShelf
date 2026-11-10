<?php

namespace App\Services\Document;

/**
 * Structured difference between two revision snapshots (spec 07 section 2.3).
 *
 * Lines are matched by catalog item id (when it is unique on both sides), then by identical name,
 * then by position; matching ignores order, so a reorder alone is not a change. Money is integer
 * minor units, quantities are fixed decimal text, as the snapshot stores them.
 *
 * Each change is {kind, path, before, after} plus {line} for changes inside a matched line.
 */
class RevisionDiffService
{
    public const KINDS = [
        'header_changed', 'line_added', 'line_removed', 'quantity_changed', 'unit_price_changed',
        'discount_changed', 'description_changed', 'tax_changed', 'line_changed',
    ];

    private const LINE_KIND = [
        'quantity' => 'quantity_changed',
        'price' => 'unit_price_changed',
        'discount_type' => 'discount_changed',
        'discount' => 'discount_changed',
        'discount_val' => 'discount_changed',
        'name' => 'description_changed',
        'description' => 'description_changed',
        'tax' => 'tax_changed',
        'taxes' => 'tax_changed',
        'unit_name' => 'line_changed',
        'invoice_item_id' => 'line_changed',
    ];

    // derived from quantity, price, discount and tax, so reporting them would count one edit twice
    private const DERIVED_LINE = ['total'];

    public static function diff(array $before, array $after): array
    {
        $changes = [];

        $headerKeys = array_unique([...array_keys($before), ...array_keys($after)]);
        sort($headerKeys);
        foreach ($headerKeys as $key) {
            if (in_array($key, ['items', 'taxes'], true)) {
                continue;
            }
            $a = $before[$key] ?? null;
            $b = $after[$key] ?? null;
            if ($a !== $b) {
                $changes[] = ['kind' => 'header_changed', 'path' => $key, 'before' => self::scalar($a), 'after' => self::scalar($b)];
            }
        }

        $taxBefore = self::taxSummary($before['taxes'] ?? []);
        $taxAfter = self::taxSummary($after['taxes'] ?? []);
        if ($taxBefore !== $taxAfter) {
            $changes[] = ['kind' => 'tax_changed', 'path' => 'taxes', 'before' => $taxBefore, 'after' => $taxAfter];
        }

        return [...$changes, ...self::lineChanges($before['items'] ?? [], $after['items'] ?? [])];
    }

    /**
     * Count of changes per kind, for timelines. Rows written before kinds existed count as header changes.
     */
    public static function summarise(?array $changes): array
    {
        $summary = ['changes' => 0, 'kinds' => []];

        foreach ($changes ?? [] as $change) {
            $kind = $change['kind'] ?? 'header_changed';
            $summary['changes']++;
            $summary['kinds'][$kind] = ($summary['kinds'][$kind] ?? 0) + 1;
        }

        return $summary;
    }

    private static function lineChanges(array $old, array $new): array
    {
        $pairs = self::match($old, $new);
        $changes = [];

        foreach ($new as $newIndex => $line) {
            $oldIndex = array_search($newIndex, $pairs, true);

            if ($oldIndex === false) {
                $changes[] = ['kind' => 'line_added', 'path' => "items[{$newIndex}]", 'line' => $line['name'] ?? null, 'before' => null, 'after' => self::lineSummary($line)];

                continue;
            }

            array_push($changes, ...self::compareLine($old[$oldIndex], $line, $newIndex));
        }

        foreach ($old as $oldIndex => $line) {
            if (! array_key_exists($oldIndex, $pairs)) {
                $changes[] = ['kind' => 'line_removed', 'path' => "items[{$oldIndex}]", 'line' => $line['name'] ?? null, 'before' => self::lineSummary($line), 'after' => null];
            }
        }

        return $changes;
    }

    private static function compareLine(array $old, array $new, int $index): array
    {
        $changes = [];
        $keys = array_unique([...array_keys($old), ...array_keys($new)]);
        sort($keys);
        $taxEmitted = false;

        foreach ($keys as $key) {
            if (in_array($key, self::DERIVED_LINE, true)) {
                continue;
            }

            $a = $old[$key] ?? null;
            $b = $new[$key] ?? null;
            if ($a === $b) {
                continue;
            }

            $kind = self::LINE_KIND[$key] ?? 'line_changed';

            if ($key === 'taxes') {
                $a = self::taxSummary($a ?? []);
                $b = self::taxSummary($b ?? []);
            }

            // a changed tax list nearly always changes the tax amount too: report the line's tax once
            if ($kind === 'tax_changed') {
                if ($taxEmitted) {
                    continue;
                }
                $taxEmitted = true;
                $readable = self::taxSummary($old['taxes'] ?? []) !== self::taxSummary($new['taxes'] ?? []);
                $a = $readable ? self::taxSummary($old['taxes'] ?? []) : self::scalar($old['tax'] ?? null);
                $b = $readable ? self::taxSummary($new['taxes'] ?? []) : self::scalar($new['tax'] ?? null);
            }

            $changes[] = ['kind' => $kind, 'path' => "items[{$index}].{$key}", 'line' => $new['name'] ?? null, 'before' => self::scalar($a), 'after' => self::scalar($b)];
        }

        return $changes;
    }

    /**
     * @return array<int,int> old index => new index
     */
    private static function match(array $old, array $new): array
    {
        $pairs = [];
        $taken = [];

        $unique = function (array $lines, callable $key, array $skip): array {
            $groups = [];
            foreach ($lines as $i => $line) {
                if (in_array($i, $skip, true)) {
                    continue;
                }
                $k = $key($line);
                if ($k !== null) {
                    $groups[$k][] = $i;
                }
            }

            return array_filter($groups, fn ($g) => count($g) === 1);
        };

        $pass = function (callable $key) use ($old, $new, &$pairs, &$taken, $unique) {
            $oldUnique = $unique($old, $key, array_keys($pairs));
            $newUnique = $unique($new, $key, $taken);

            foreach ($oldUnique as $k => [$oldIndex]) {
                if (isset($newUnique[$k])) {
                    $pairs[$oldIndex] = $newUnique[$k][0];
                    $taken[] = $newUnique[$k][0];
                }
            }
        };

        $pass(fn ($l) => isset($l['invoice_item_id']) ? (string) $l['invoice_item_id'] : null);
        $pass(fn ($l) => isset($l['name']) && $l['name'] !== '' ? (string) $l['name'] : null);

        foreach (array_keys($old) as $i) {
            if (! array_key_exists($i, $pairs) && isset($new[$i]) && ! in_array($i, $taken, true)) {
                $pairs[$i] = $i;
                $taken[] = $i;
            }
        }

        return $pairs;
    }

    private static function lineSummary(array $line): array
    {
        return array_intersect_key($line, array_flip(['name', 'quantity', 'price', 'total']));
    }

    private static function taxSummary(array $taxes): string
    {
        $parts = array_map(fn ($t) => trim(($t['name'] ?? 'Tax').' '.($t['percent'] ?? '').'%'), $taxes);
        sort($parts);

        return implode(', ', $parts);
    }

    private static function scalar(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value['name'] ?? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $value;
    }
}
