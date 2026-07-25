<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Support;

final class Arr
{
    /**
     * @param array<int|string, mixed> $row
     */
    public static function isAssoc(array $row): bool
    {
        if ($row === []) {
            return false;
        }

        return array_keys($row) !== range(0, count($row) - 1);
    }

    /**
     * @param array<int|string, mixed> $row
     * @return list<string>
     */
    public static function stringKeys(array $row): array
    {
        return array_map(static fn($key): string => (string) $key, array_keys($row));
    }
}
