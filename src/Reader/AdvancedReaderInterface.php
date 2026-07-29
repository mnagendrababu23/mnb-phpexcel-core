<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Reader;

use Mnb\PHPExcel\Core\RichText;
use Mnb\PHPExcel\Reader\State\CellSnapshot;

/**
 * Optional advanced workbook capability used by XLSX and future rich formats.
 * Core depends only on this contract; concrete format packages provide the implementation.
 */
interface AdvancedReaderInterface extends IterableReaderInterface, FormatAwareReaderInterface, SheetNamesReaderInterface, InspectableReaderInterface
{
    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function readProtection(string $path, int|string $sheet = 1, array $options = []): array;

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function readSheetMetadata(string $path, int|string $sheet = 1, array $options = []): array;

    /** @param array<string,mixed> $options */
    public function readCell(string $path, string $cell, int|string $sheet = 1, array $options = []): mixed;

    /** @param list<string> $cells @param array<string,mixed> $options @return array<string,mixed> */
    public function readCells(string $path, array $cells, int|string $sheet = 1, array $options = []): array;

    /** @param array<string,mixed> $options @return array<int,array<int,mixed>> */
    public function readRange(string $path, string $range, int|string $sheet = 1, array $options = []): array;

    /** @param array<string,mixed> $options */
    public function readCellDetails(string $path, string $cell, int|string $sheet = 1, array $options = []): CellSnapshot;

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function readCellStyle(string $path, string $cell, int|string $sheet = 1, array $options = []): array;

    /** @param array<string,mixed> $options @return array<string,array<string,mixed>> */
    public function readRangeStyles(string $path, string $range, int|string $sheet = 1, array $options = []): array;

    /** @param array<string,mixed> $options */
    public function readRichTextCell(string $path, string $cell, int|string $sheet = 1, array $options = []): ?RichText;

    /** @param array<string,mixed> $options @return list<array<string,mixed>> */
    public function images(string $path, int|string $sheet = 1, bool $includeBytes = false, array $options = []): array;

    /** @param array<string,mixed> $options @return list<array<string,mixed>> */
    public function extractImages(string $path, string $directory, int|string $sheet = 1, bool $overwrite = false, array $options = []): array;

    /** @param array<string,mixed> $options */
    public function calculateCell(string $path, string $cell, int|string $sheet = 1, array $options = []): mixed;

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function calculateRange(string $path, string $range, int|string $sheet = 1, array $options = []): array;
}
