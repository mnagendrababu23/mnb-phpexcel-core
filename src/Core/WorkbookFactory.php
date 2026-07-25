<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Core;

/** Small, dependency-free factory used by format-specific modular facades. */
final class WorkbookFactory
{
    /**
     * @param iterable<array<int|string,mixed>|mixed> $rows
     */
    public static function worksheet(iterable $rows, string $name = 'Sheet1', bool $withHeader = false): WorksheetData
    {
        $normalized = [];
        $sourceKeys = [];
        $headerAdded = false;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                $row = [$row];
            }

            if ($sourceKeys === [] && !array_is_list($row)) {
                $sourceKeys = array_keys($row);
            }

            if ($withHeader && !$headerAdded) {
                $keys = $sourceKeys !== [] ? $sourceKeys : array_keys($row);
                $normalized[] = array_map(static fn(int|string $key): string => (string) $key, $keys);
                $headerAdded = true;
            }

            $normalized[] = array_values($row);
        }

        return new WorksheetData(
            name: $name,
            rows: $normalized,
            hasHeader: $withHeader && $normalized !== [],
            sourceColumnKeys: $sourceKeys,
            dataRowStart: $withHeader && $normalized !== [] ? 1 : 0,
            dataRowCount: max(0, count($normalized) - (($withHeader && $normalized !== []) ? 1 : 0))
        );
    }

    /**
     * @param iterable<array<int|string,mixed>|mixed> $rows
     */
    public static function workbook(iterable $rows, string $sheetName = 'Sheet1', bool $withHeader = false): WorkbookData
    {
        return new WorkbookData([self::worksheet($rows, $sheetName, $withHeader)]);
    }
}
