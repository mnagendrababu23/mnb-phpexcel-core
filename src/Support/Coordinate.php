<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Support;

final class Coordinate
{
    /** @var array<int,string> */
    private static array $indexToNameCache = [];

    /** @var array<string,int> */
    private static array $nameToIndexCache = [];

    public static function columnIndexToName(int $index): string
    {
        if ($index < 1) {
            throw new MnbExcelException('Column index must be greater than zero.');
        }

        if (isset(self::$indexToNameCache[$index])) {
            return self::$indexToNameCache[$index];
        }

        $originalIndex = $index;
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)) . $name;
            $index = intdiv($index, 26);
        }

        self::$indexToNameCache[$originalIndex] = $name;
        self::$nameToIndexCache[$name] = $originalIndex;

        return $name;
    }

    public static function columnNameToIndex(string $name): int
    {
        $name = strtoupper(trim($name));
        if ($name === '' || !preg_match('/^[A-Z]+$/', $name)) {
            throw new MnbExcelException('Invalid column name: ' . $name);
        }

        if (isset(self::$nameToIndexCache[$name])) {
            return self::$nameToIndexCache[$name];
        }

        $index = 0;
        $length = strlen($name);
        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord($name[$i]) - 64);
        }

        self::$nameToIndexCache[$name] = $index;
        self::$indexToNameCache[$index] = $name;

        return $index;
    }

    /**
     * @return array{0:int,1:int}
     */
    public static function splitCellRef(string $cellRef): array
    {
        if (!preg_match('/^([A-Z]+)(\d+)$/i', $cellRef, $matches)) {
            throw new MnbExcelException('Invalid cell reference: ' . $cellRef);
        }

        return [self::columnNameToIndex($matches[1]), (int) $matches[2]];
    }
}
