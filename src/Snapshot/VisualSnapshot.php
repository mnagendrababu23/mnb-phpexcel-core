<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Snapshot;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use Mnb\PHPExcel\Core\CellValue;
use Mnb\PHPExcel\Core\RichText;
use Mnb\PHPExcel\Core\StyleNormalizer;
use Mnb\PHPExcel\Core\WorkbookData;
use Mnb\PHPExcel\Core\WorksheetData;
use Mnb\PHPExcel\Support\Coordinate;
use Mnb\PHPExcel\Support\MnbExcelException;

/** Shared schema, JSON codec, validation, and WorkbookData hydration. */
final class VisualSnapshot
{
    public const SCHEMA = 'mnb-phpexcel.visual-snapshot';
    public const VERSION = '1.0';

    /** @param array<string,mixed> $snapshot */
    public static function toJson(array $snapshot, int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE): string
    {
        self::assertValid($snapshot);
        try {
            return json_encode($snapshot, $flags | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new MnbExcelException('Unable to encode visual snapshot JSON.', previous: $e);
        }
    }

    /** @return array<string,mixed> */
    public static function fromJson(string $json): array
    {
        try {
            $snapshot = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new MnbExcelException('Visual snapshot JSON is invalid.', previous: $e);
        }
        if (!is_array($snapshot)) {
            throw new MnbExcelException('Visual snapshot JSON must decode to an object.');
        }
        self::assertValid($snapshot);
        return $snapshot;
    }

    /** @param array<string,mixed> $snapshot */
    public static function assertValid(array $snapshot): void
    {
        if (($snapshot['schema'] ?? null) !== self::SCHEMA) {
            throw new MnbExcelException('Unsupported visual snapshot schema.');
        }
        if (($snapshot['schema_version'] ?? null) !== self::VERSION) {
            throw new MnbExcelException('Unsupported visual snapshot schema version: ' . (string) ($snapshot['schema_version'] ?? 'missing'));
        }
        if (!is_array($snapshot['sheets'] ?? null) || $snapshot['sheets'] === []) {
            throw new MnbExcelException('Visual snapshot must contain at least one worksheet.');
        }
        $names = [];
        foreach ($snapshot['sheets'] as $index => $sheet) {
            if (!is_array($sheet)) {
                throw new MnbExcelException('Visual snapshot worksheet ' . ($index + 1) . ' must be an object.');
            }
            $name = trim((string) ($sheet['name'] ?? ''));
            if ($name === '') {
                throw new MnbExcelException('Visual snapshot worksheet ' . ($index + 1) . ' has no name.');
            }
            $key = strtolower($name);
            if (isset($names[$key])) {
                throw new MnbExcelException('Visual snapshot contains duplicate worksheet name: ' . $name);
            }
            $names[$key] = true;
            if (!is_array($sheet['cells'] ?? [])) {
                throw new MnbExcelException('Visual snapshot worksheet cells must be an object: ' . $name);
            }
        }
    }

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $options */
    public static function toWorkbookData(array $snapshot, array $options = []): WorkbookData
    {
        self::assertValid($snapshot);
        $styleTable = is_array($snapshot['styles'] ?? null) ? $snapshot['styles'] : [];
        $metadata = self::writerMetadata(is_array($snapshot['workbook']['metadata'] ?? null) ? $snapshot['workbook']['metadata'] : []);
        $metadata['_mnb_active_sheet'] = $snapshot['workbook']['active_sheet'] ?? 1;
        $metadata['_mnb_sheet_states'] = [];
        if (array_key_exists('date1904', (array) ($snapshot['workbook'] ?? []))) {
            $metadata['date1904'] = (bool) $snapshot['workbook']['date1904'];
        }

        $worksheets = [];
        $maximumCells = max(1, (int) ($options['max_cells'] ?? 1_000_000));
        $totalCells = 0;

        foreach ($snapshot['sheets'] as $sheetIndex => $sheet) {
            $name = (string) $sheet['name'];
            $metadata['_mnb_sheet_states'][$name] = (string) ($sheet['state'] ?? 'visible');
            $cells = is_array($sheet['cells'] ?? null) ? $sheet['cells'] : [];
            [$maxColumn, $maxRow] = self::sheetBounds($sheet, $cells);
            $totalCells += $maxColumn * $maxRow;
            if ($totalCells > $maximumCells) {
                throw new MnbExcelException('Visual snapshot exceeds the configured workbook cell limit.');
            }
            $rows = $maxRow > 0 && $maxColumn > 0
                ? array_fill(0, $maxRow, array_fill(0, $maxColumn, null))
                : [];
            $cellStyles = [];

            foreach ($cells as $coordinate => $cell) {
                if (!is_string($coordinate) || !is_array($cell)) {
                    continue;
                }
                [$column, $row] = Coordinate::splitCellRef(strtoupper($coordinate));
                if ($row < 1 || $column < 1 || $row > $maxRow || $column > $maxColumn) {
                    throw new MnbExcelException('Visual snapshot cell lies outside its declared worksheet bounds: ' . $coordinate);
                }
                $rows[$row - 1][$column - 1] = self::decodeCell($cell);
                $style = null;
                if (isset($cell['style']) && is_array($cell['style'])) {
                    $style = $cell['style'];
                } elseif (isset($cell['style_id']) && isset($styleTable[(string) $cell['style_id']]) && is_array($styleTable[(string) $cell['style_id']])) {
                    $style = $styleTable[(string) $cell['style_id']];
                }
                if (is_array($style) && $style !== []) {
                    $cellStyles[strtoupper($coordinate)] = StyleNormalizer::normalize($style);
                }
            }

            $layout = is_array($sheet['layout'] ?? null) ? $sheet['layout'] : [];
            $freeze = is_array($layout['freeze_panes'] ?? null) ? $layout['freeze_panes'] : [];
            $worksheets[] = new WorksheetData(
                name: $name,
                rows: $rows,
                columns: [],
                hasHeader: false,
                escapeFormulaLikeText: true,
                mergeCells: self::stringList($sheet['merged_cells'] ?? []),
                columnWidths: self::numericMap($layout['column_widths'] ?? []),
                rowHeights: self::numericMap($layout['row_heights'] ?? []),
                images: self::restorableImages($sheet['images'] ?? []),
                cellStyles: $cellStyles,
                hyperlinks: self::arrayList($sheet['hyperlinks'] ?? []),
                comments: self::arrayList($sheet['comments'] ?? []),
                freezeRows: max(0, (int) ($freeze['rows'] ?? 0)),
                freezeColumns: max(0, (int) ($freeze['columns'] ?? 0)),
                freezeTopLeftCell: isset($freeze['top_left_cell']) ? strtoupper((string) $freeze['top_left_cell']) : null,
                autoFilter: isset($layout['auto_filter_range']) && $layout['auto_filter_range'] !== '',
                autoFilterRange: isset($layout['auto_filter_range']) && $layout['auto_filter_range'] !== '' ? strtoupper((string) $layout['auto_filter_range']) : null,
                conditionalFormats: self::arrayList($sheet['conditional_formats'] ?? []),
                dataValidations: self::arrayList($sheet['data_validations'] ?? []),
            );
        }

        return new WorkbookData($worksheets, $metadata);
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    private static function writerMetadata(array $metadata): array
    {
        // Visual snapshots keep rich metadata sections for inspection. Writers
        // need the original flat WorkbookData property shape, never nested
        // section arrays that PHP would coerce to the string "Array".
        if (!isset($metadata['document']) && !isset($metadata['revision']) && !isset($metadata['application']) && !isset($metadata['custom_properties'])) {
            return array_filter(
                $metadata,
                static fn(mixed $value): bool => is_scalar($value) || $value === null || $value instanceof DateTimeInterface || is_array($value),
            );
        }

        $flat = [];
        $document = is_array($metadata['document'] ?? null) ? $metadata['document'] : [];
        foreach (['title', 'subject', 'creator', 'description', 'category', 'content_status', 'identifier', 'language', 'document_version', 'manager', 'company'] as $key) {
            if (array_key_exists($key, $document) && (is_scalar($document[$key]) || $document[$key] === null)) {
                $flat[$key] = $document[$key];
            }
        }
        if (isset($document['keywords'])) {
            $flat['keywords'] = is_array($document['keywords'])
                ? implode(', ', array_map('strval', array_filter($document['keywords'], 'is_scalar')))
                : (is_scalar($document['keywords']) ? (string) $document['keywords'] : '');
        }

        $revision = is_array($metadata['revision'] ?? null) ? $metadata['revision'] : [];
        $revisionMap = [
            'last_saved_by' => 'last_modified_by',
            'revision_number' => 'revision_number',
            'total_editing_time_seconds' => 'total_editing_time_seconds',
            'last_printed_at' => 'last_printed_at',
            'document_created_at' => 'document_created_at',
            'document_modified_at' => 'document_modified_at',
        ];
        foreach ($revisionMap as $source => $target) {
            if (array_key_exists($source, $revision) && (is_scalar($revision[$source]) || $revision[$source] === null)) {
                $flat[$target] = $revision[$source];
            }
        }

        $application = is_array($metadata['application'] ?? null) ? $metadata['application'] : [];
        foreach (['application', 'application_version', 'company', 'manager'] as $key) {
            if (array_key_exists($key, $application) && (is_scalar($application[$key]) || $application[$key] === null)) {
                $flat[$key] = $application[$key];
            }
        }

        $customSection = is_array($metadata['custom_properties'] ?? null) ? $metadata['custom_properties'] : [];
        $customItems = is_array($customSection['items'] ?? null) ? $customSection['items'] : $customSection;
        $custom = [];
        foreach ($customItems as $key => $item) {
            if (is_int($key) && is_array($item)) {
                $name = trim((string) ($item['name'] ?? ''));
                if ($name === '' || !array_key_exists('value', $item) || $item['value'] === null || ($item['type'] ?? null) === 'opaque') {
                    continue;
                }
                $definition = ['value' => $item['value']];
                if (isset($item['type']) && is_string($item['type'])) {
                    $definition['type'] = $item['type'];
                }
                $custom[$name] = $definition;
                continue;
            }
            if (is_string($key) && (is_scalar($item) || $item === null || is_array($item))) {
                $custom[$key] = $item;
            }
        }
        if ($custom !== []) {
            $flat['custom_properties'] = $custom;
        }

        return $flat;
    }

    /** @param array<string,mixed> $cell */
    public static function decodeCell(array $cell): mixed
    {
        if (isset($cell['rich_text']) && is_array($cell['rich_text'])) {
            $runs = $cell['rich_text']['runs'] ?? $cell['rich_text'];
            if (is_array($runs)) {
                return RichText::fromArray(array_values(array_filter($runs, 'is_array')));
            }
        }

        $type = strtolower((string) ($cell['type'] ?? 'text'));
        return match ($type) {
            'blank', 'null' => CellValue::blank(),
            'boolean', 'bool' => CellValue::bool((bool) ($cell['value'] ?? false)),
            'number', 'numeric', 'integer', 'float' => CellValue::number($cell['value'] ?? 0),
            'date', 'datetime' => CellValue::date((string) ($cell['value'] ?? ''), ['format' => (string) ($cell['format'] ?? 'yyyy-mm-dd')]),
            'formula' => CellValue::formula((string) ($cell['formula'] ?? $cell['value'] ?? ''), $cell['cached_value'] ?? null, is_array($cell['formula_options'] ?? null) ? $cell['formula_options'] : []),
            'error' => CellValue::error((string) ($cell['value'] ?? '#VALUE!')),
            default => CellValue::text($cell['value'] ?? ''),
        };
    }

    /** @param array<string,mixed> $sheet @param array<string,mixed> $cells @return array{int,int} */
    private static function sheetBounds(array $sheet, array $cells): array
    {
        $maxColumn = 0;
        $maxRow = 0;
        $dimension = strtoupper((string) ($sheet['dimension'] ?? ''));
        if ($dimension !== '') {
            $end = str_contains($dimension, ':') ? explode(':', $dimension, 2)[1] : $dimension;
            try {
                [$maxColumn, $maxRow] = Coordinate::splitCellRef(str_replace('$', '', $end));
            } catch (\Throwable) {
                $maxColumn = $maxRow = 0;
            }
        }
        foreach (array_keys($cells) as $coordinate) {
            if (!is_string($coordinate)) {
                continue;
            }
            try {
                [$column, $row] = Coordinate::splitCellRef(strtoupper($coordinate));
                $maxColumn = max($maxColumn, $column);
                $maxRow = max($maxRow, $row);
            } catch (\Throwable) {
                throw new MnbExcelException('Invalid cell reference in visual snapshot: ' . $coordinate);
            }
        }
        return [max(1, $maxColumn), max(1, $maxRow)];
    }

    /** @return list<string> */
    private static function stringList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        return array_values(array_map('strval', array_filter($values, static fn (mixed $value): bool => is_scalar($value))));
    }

    /** @return list<array<string,mixed>> */
    private static function arrayList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        return array_values(array_filter($values, 'is_array'));
    }

    /** @return array<int|string,float|int> */
    private static function numericMap(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $result = [];
        foreach ($values as $key => $value) {
            if ((is_int($key) || is_string($key)) && is_numeric($value) && (float) $value >= 0) {
                $result[$key] = (float) $value;
            }
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private static function restorableImages(mixed $images): array
    {
        if (!is_array($images)) {
            return [];
        }
        $result = [];
        foreach ($images as $image) {
            if (!is_array($image) || !is_string($image['path'] ?? null) || !is_file($image['path'])) {
                continue;
            }
            $result[] = $image;
        }
        return $result;
    }
}
