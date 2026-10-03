<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Metadata;

final class MetadataDiff
{
    /** @param array<string,mixed> $before @param array<string,mixed> $after @return array<string,mixed> */
    public static function between(array $before, array $after): array
    {
        $changes = [];
        self::walk('', $before, $after, $changes);
        return ['changed' => $changes !== [], 'count' => count($changes), 'changes' => $changes];
    }

    /** @param array<string,mixed> $changes */
    private static function walk(string $path, mixed $before, mixed $after, array &$changes): void
    {
        if (is_array($before) && is_array($after)) {
            $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
            foreach ($keys as $key) {
                $child = $path === '' ? (string) $key : $path . '.' . $key;
                self::walk($child, $before[$key] ?? null, $after[$key] ?? null, $changes);
            }
            return;
        }
        if ($before !== $after) $changes[$path] = ['before' => $before, 'after' => $after];
    }
}
