<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Core;

final class WorksheetData
{
    /**
     * @param list<list<mixed>> $rows
     * @param array<int|string, string> $columns
     * @param list<string> $textColumns
     * @param array<string, string> $dateColumns
     * @param list<string> $numberColumns
     * @param list<string> $mergeCells
     * @param array<int|string, float|int> $columnWidths
     * @param array<int, float|int> $rowHeights
     * @param list<array{path:string,cell:string,width?:int,height?:int,name?:string}> $images
     * @param array<string, array<string,mixed>> $namedStyles
     * @param array<int|string, string|array<string,mixed>> $columnStyles
     * @param array<int, string|array<string,mixed>> $rowStyles
     * @param array<string, string|array<string,mixed>> $cellStyles
     * @param array<string, string|array<string,mixed>> $rangeStyles
     * @param list<array{cell:string,url:string,display?:string,tooltip?:string}> $hyperlinks
     * @param list<array{cell:string,author:string,text:string,width?:float|int,height?:float|int,visible?:bool}> $comments
     * @param list<int|string> $sourceColumnKeys Original associative keys, in worksheet column order.
     * @param list<array<string,mixed>> $filterColumns
     * @param list<array<string,mixed>> $conditionalFormats
     * @param list<array<string,mixed>> $dataValidations
     * @param list<array<string,mixed>> $charts
     * @param list<array<string,mixed>> $pivotTables
     */
    public function __construct(
        public string $name,
        public array $rows,
        public array $columns = [],
        public bool $hasHeader = false,
        public array $textColumns = [],
        public array $dateColumns = [],
        public array $numberColumns = [],
        public bool $freezeHeader = false,
        public bool $autoFilter = false,
        public bool $escapeFormulaLikeText = true,
        public array|string $headerStyle = [],
        public array $mergeCells = [],
        public array $columnWidths = [],
        public array $rowHeights = [],
        public array $images = [],
        public ?int $headerRowIndex = null,
        public array $namedStyles = [],
        public array $columnStyles = [],
        public array $rowStyles = [],
        public array $cellStyles = [],
        public array $rangeStyles = [],
        public array $hyperlinks = [],
        public array $comments = [],
        public array $sourceColumnKeys = [],
        public int $dataRowStart = 0,
        public ?int $dataRowCount = null,
        public int $freezeRows = 0,
        public int $freezeColumns = 0,
        public ?string $freezeTopLeftCell = null,
        public ?string $autoFilterRange = null,
        public array $filterColumns = [],
        public array $conditionalFormats = [],
        public array $dataValidations = [],
        public array $charts = [],
        public array $pivotTables = []
    ) {
    }

    /**
     * Return rows suitable for JSON/XML export while restoring associative
     * source keys for the workbook's data-row region.
     *
     * Presentation rows such as generated headers, titles, summaries, and
     * footers remain indexed unless $dataOnly is true.
     *
     * @return list<array<string,mixed>|list<mixed>>
     */
    public function rowsForStructuredExport(bool $preserveAssociative = true, bool $dataOnly = false): array
    {
        $rowCount = $this->dataRowCount ?? max(0, count($this->rows) - $this->dataRowStart);
        $start = max(0, $this->dataRowStart);
        $end = $start + max(0, $rowCount);

        $rows = $dataOnly ? array_slice($this->rows, $start, $rowCount) : $this->rows;
        $absoluteStart = $dataOnly ? $start : 0;
        $output = [];

        foreach (array_values($rows) as $index => $row) {
            $absoluteIndex = $absoluteStart + $index;
            $isDataRow = $absoluteIndex >= $start && $absoluteIndex < $end;

            if (!$preserveAssociative || !$isDataRow || $this->sourceColumnKeys === []) {
                $output[] = array_values($row);
                continue;
            }

            $item = [];
            foreach (array_values($this->sourceColumnKeys) as $columnIndex => $key) {
                $item[(string) $key] = $row[$columnIndex] ?? null;
            }
            $output[] = $item;
        }

        return $output;
    }
}
