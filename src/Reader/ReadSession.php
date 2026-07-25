<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Reader;

use Mnb\PHPExcel\Import\SqlImporter;
use Mnb\PHPExcel\Import\ImportQualityAnalyzer;
use Mnb\PHPExcel\Support\AtomicFileWriter;
use Mnb\PHPExcel\Support\DatabaseConnectionFactory;
use Mnb\PHPExcel\Support\ErrorCode;
use Mnb\PHPExcel\Support\MnbExcelException;
use Mnb\PHPExcel\Support\Coordinate;
use Mnb\PHPExcel\Support\LocaleNormalizer;
use Mnb\PHPExcel\Validation\ArrayValidator;
use Mnb\PHPExcel\Reader\Options\ReaderOptions;
use Mnb\PHPExcel\Reader\Options\ReadMode;
use Mnb\PHPExcel\Reader\Options\RowErrorPolicy;
use Mnb\PHPExcel\Reader\State\ReadProgress;
use Mnb\PHPExcel\Reader\State\RowState;
use Throwable;
use Mnb\PHPExcel\Writer\JsonWriter;
use Mnb\PHPExcel\Writer\XmlWriter;
use Mnb\PHPExcel\Compatibility\XlsReader;
use PDO;

final class ReadSession
{
    private int|string $sheetNumber = 1;

    /** @var array<string,mixed> */
    private array $defaultOptions = [];

    /** @var list<array<string,mixed>> */
    private array $lastRowErrors = [];

    /** @param array<string,mixed>|ReaderOptions $defaultOptions */
    public function __construct(private readonly string $path, private readonly ReaderInterface $reader, array|ReaderOptions $defaultOptions = [])
    {
        $this->defaultOptions = $defaultOptions instanceof ReaderOptions ? $defaultOptions->toArray() : ReaderOptions::fromArray($defaultOptions)->toArray();
        if (!is_file($path)) {
            throw MnbExcelException::withCode('File not found: ' . $path, ErrorCode::FILE_NOT_FOUND, ['path' => $path]);
        }
    }

    public function sheet(int|string $sheetNumber): self
    {
        if ((is_int($sheetNumber) || ctype_digit((string) $sheetNumber)) && (int) $sheetNumber < 1) {
            throw new MnbExcelException('Sheet number must be greater than zero.');
        }
        if (is_string($sheetNumber) && trim($sheetNumber) === '') {
            throw new MnbExcelException('Sheet name cannot be empty.');
        }

        $clone = clone $this;
        $clone->sheetNumber = $sheetNumber;
        return $clone;
    }

    /**
     * Treat the selected 1-based normalized data row as column headers.
     * Empty rows are excluded when skip_empty_rows=true (the default).
     */
    public function withHeaderRow(int $row = 1): self
    {
        return $this->headerAtDataRow($row);
    }

    /** Treat the selected 1-based normalized data row as column headers. */
    public function headerAtDataRow(int $row = 1): self
    {
        if ($row < 1) {
            throw new MnbExcelException('Header data row must be greater than zero.');
        }

        $clone = clone $this;
        $clone->defaultOptions['header_row'] = $row;
        $clone->defaultOptions['header_row_mode'] = 'data';
        return $clone;
    }

    /** Treat the exact 1-based physical source row as column headers. */
    public function headerAtPhysicalRow(int $row): self
    {
        if ($row < 1) {
            throw new MnbExcelException('Header physical row must be greater than zero.');
        }

        $clone = clone $this;
        $clone->defaultOptions['header_row'] = $row;
        $clone->defaultOptions['header_row_mode'] = 'physical';
        return $clone;
    }

    /** Use the first non-empty source row as column headers. */
    public function firstNonEmptyRowAsHeader(): self
    {
        $clone = clone $this;
        $clone->defaultOptions['header_row'] = true;
        $clone->defaultOptions['header_row_mode'] = 'first_non_empty';
        return $clone;
    }

    /** @param array<string,mixed>|ReaderOptions $options */
    public function withOptions(array|ReaderOptions $options): self
    {
        $clone = clone $this;
        $values = $options instanceof ReaderOptions ? $options->toArray() : ReaderOptions::fromArray($options)->toArray();
        $clone->defaultOptions = array_replace($clone->defaultOptions, $values);
        return $clone;
    }

    public function range(?int $startRow = null, ?int $endRow = null, int|string|null $startColumn = null, int|string|null $endColumn = null): self
    {
        return $this->withOptions(ReaderOptions::defaults()->withRange($startRow, $endRow, $startColumn, $endColumn));
    }

    /** @param list<int|string> $columns */
    public function projectColumns(array $columns, bool $compact = true): self
    {
        return $this->withOptions(ReaderOptions::defaults()->withColumns($columns, $compact));
    }

    public function mode(ReadMode|string $mode): self
    {
        $resolved = ReadMode::fromMixed($mode);
        $options = ['read_mode' => $resolved->value];
        if ($resolved === ReadMode::Streaming) {
            $options += ['shared_strings_mode' => 'auto', 'stream_json_array' => true];
        }
        return $this->withOptions($options);
    }

    public function streaming(): self
    {
        return $this->mode(ReadMode::Streaming);
    }

    public function normal(): self
    {
        return $this->mode(ReadMode::Normal);
    }

    public function onProgress(callable $callback, int $everyRows = 1000): self
    {
        return $this->withOptions(ReaderOptions::defaults()->withProgress($callback, $everyRows));
    }

    public function onRowError(RowErrorPolicy|string $policy, ?callable $handler = null): self
    {
        return $this->withOptions(ReaderOptions::defaults()->withRowErrorPolicy($policy, $handler));
    }

    /** @return array<string,mixed> */
    public function options(): array
    {
        return $this->defaultOptions;
    }

    /** @return list<array<string,mixed>> */
    public function rowErrors(): array
    {
        return $this->lastRowErrors;
    }

    public function withoutHeaderRow(): self
    {
        $clone = clone $this;
        $clone->defaultOptions['header_row'] = false;
        unset($clone->defaultOptions['header_row_mode']);
        return $clone;
    }

    /** Skip normalized data rows after an optional header row. */
    public function skip(int $rows): self
    {
        if ($rows < 0) {
            throw new MnbExcelException('Skip rows cannot be negative.');
        }
        return $this->withOptions(['offset' => $rows]);
    }

    /** Limit normalized data rows returned by rows(), eachRow(), chunk(), or first(). */
    public function limit(int $rows): self
    {
        if ($rows < 0) {
            throw new MnbExcelException('Row limit cannot be negative.');
        }
        return $this->withOptions(['limit' => $rows]);
    }

    /** @param list<int|string> $columns */
    public function selectColumns(array $columns): self
    {
        return $this->withOptions(['select_columns' => array_values($columns)]);
    }

    /** @return list<string> */
    public function sheetNames(): array
    {
        if ($this->reader instanceof XlsxReader) {
            return (new XlsxInspector())->sheetNames($this->path);
        }

        if ($this->reader instanceof JsonReader || $this->reader instanceof XmlReader || $this->reader instanceof OdsReader) {
            return $this->reader->sheetNames($this->path, $this->defaultOptions);
        }
        if ($this->reader instanceof XlsReader) {
            return $this->reader->sheetNames($this->path);
        }

        return ['Sheet1'];
    }

    /** @return array<string,mixed> */
    public function inspect(): array
    {
        if ($this->reader instanceof XlsxReader) {
            return (new XlsxInspector())->inspect($this->path);
        }

        if ($this->reader instanceof JsonReader || $this->reader instanceof XmlReader || $this->reader instanceof OdsReader) {
            $names = $this->reader->sheetNames($this->path, $this->defaultOptions);
            return [
                'status' => 'ok',
                'file' => $this->path,
                'size_bytes' => (int) filesize($this->path),
                'format' => $this->reader instanceof JsonReader ? 'json' : ($this->reader instanceof OdsReader ? 'ods' : 'xml'),
                'sheets' => array_map(
                    static fn(string $name, int $index): array => ['index' => $index + 1, 'name' => $name, 'state' => 'visible'],
                    $names,
                    array_keys($names)
                ),
                'warnings' => [],
                'errors' => [],
            ];
        }

        return [
            'status' => 'ok',
            'file' => $this->path,
            'size_bytes' => (int) filesize($this->path),
            'sheets' => [['index' => 1, 'name' => 'Sheet1', 'state' => 'visible']],
            'warnings' => [],
            'errors' => [],
        ];
    }


    /**
     * Return XLSX-only cell metadata for the selected sheet: rich text runs, comments, hyperlinks, and advanced object inventory.
     * CSV/JSON readers return an empty metadata shape.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function sheetMetadata(array $options = []): array
    {
        $options = array_replace($this->defaultOptions, $options);
        if ($this->reader instanceof XlsxReader) {
            return $this->reader->readSheetMetadata($this->path, $this->sheetNumber, $options);
        }

        return [
            'rich_text' => [],
            'comments' => [],
            'hyperlinks' => [],
            'advanced_objects' => [],
            'summary' => [
                'rich_text_cells' => 0,
                'comments' => 0,
                'hyperlinks' => 0,
                'advanced_object_parts' => 0,
            ],
        ];
    }

    /**
     * Iterate normalized rows. CSV and XLSX readers are forward-only here;
     * JSON and XML may buffer their document structure before yielding rows.
     *
     * @param array<string,mixed> $options
     * @return \Generator<int,array<string,mixed>|list<mixed>>
     */
    public function rows(array|ReaderOptions $options = []): \Generator
    {
        $incoming = $options instanceof ReaderOptions ? $options->toArray() : ReaderOptions::fromArray($options)->toArray();
        $options = array_replace($this->defaultOptions, $incoming);
        $this->lastRowErrors = [];
        $startedAt = microtime(true);
        $sourceRowsSeen = 0;
        $outputRows = 0;
        $errorRows = 0;
        $stateCallback = $options['row_state_callback'] ?? null;
        $progressCallback = $options['progress'] ?? null;
        $progressEvery = max(1, (int) ($options['progress_every_rows'] ?? 1000));
        $sessionSelectors = $this->sessionProjectionSelectors($options);
        $explicitSelectors = (array) ($options['select_columns'] ?? []);
        $postReadSelectors = array_values(array_unique(array_merge($sessionSelectors, $explicitSelectors), SORT_REGULAR));

        $notify = function (RowState $state) use (&$sourceRowsSeen, &$outputRows, &$errorRows, $stateCallback, $progressCallback, $progressEvery, $startedAt): void {
            $sourceRowsSeen = max($sourceRowsSeen, $state->sourceRow);
            if (!$state->skipped) {
                $outputRows = max($outputRows, $state->outputRow);
            }
            if ($state->errors !== []) {
                $errorRows++;
            }
            if (is_callable($stateCallback)) {
                $stateCallback($state);
            }
            if (is_callable($progressCallback) && ($sourceRowsSeen % $progressEvery === 0)) {
                $progressCallback(new ReadProgress(
                    $this->path,
                    $this->sheetNumber,
                    $sourceRowsSeen,
                    $outputRows,
                    $errorRows,
                    microtime(true) - $startedAt,
                    false
                ));
            }
        };

        try {
            // Global empty-column removal and generated extra-column names require
            // seeing the complete selected source range before yielding stable rows.
            $requiresBufferedShape = (bool) ($options['skip_empty_columns'] ?? $options['drop_empty_columns'] ?? false)
                || strtolower((string) ($options['extra_columns'] ?? 'ignore')) === 'generate';
            if ($requiresBufferedShape) {
                $bufferOptions = $options;
                unset($bufferOptions['offset'], $bufferOptions['skip'], $bufferOptions['limit'], $bufferOptions['limit_rows'], $bufferOptions['select_columns']);
                $rawRows = $this->reader->readSheet($this->path, $this->sheetNumber, $bufferOptions);
                $buffered = $this->normalizeRows($rawRows, $bufferOptions);
                $offset = max(0, (int) ($options['offset'] ?? $options['skip'] ?? 0));
                $limit = isset($options['limit']) ? max(0, (int) $options['limit']) : (isset($options['limit_rows']) ? max(0, (int) $options['limit_rows']) : null);
                $yielded = 0;
                foreach ($buffered as $index => $row) {
                    if ($index < $offset) {
                        continue;
                    }
                    if ($limit !== null && $yielded >= $limit) {
                        break;
                    }
                    try {
                        $normalized = $this->applyColumnSelection($row, $postReadSelectors, $options);
                    } catch (Throwable $e) {
                        $replacement = $this->handleRowError($e, $index + 1, $yielded + 1, $row, $options);
                        $notify(new RowState($index + 1, $yielded + 1, $this->sheetNumber, $row, [['message' => $e->getMessage(), 'type' => $e::class]], true));
                        if ($replacement === null) {
                            continue;
                        }
                        $normalized = $replacement;
                    }
                    $state = new RowState($index + 1, $yielded + 1, $this->sheetNumber, $normalized);
                    $notify($state);
                    yield $yielded => $normalized;
                    $yielded++;
                }
                return;
            }

            $skipEmptyRows = (bool) ($options['skip_empty_rows'] ?? true);
            $headerRow = $options['header_row'] ?? false;
            $headerMode = $this->resolveHeaderRowMode($headerRow, $options);
            $headerFound = $headerRow === false;
            $headers = [];
            $processedRows = 0;
            $dataRowPosition = 0;
            $physicalSequence = 0;
            $dataIndex = 0;
            $yielded = 0;
            $offset = max(0, (int) ($options['offset'] ?? $options['skip'] ?? 0));
            $limit = isset($options['limit']) ? max(0, (int) $options['limit']) : (isset($options['limit_rows']) ? max(0, (int) $options['limit_rows']) : null);
            $maxRows = isset($options['max_rows']) ? max(0, (int) $options['max_rows']) : null;
            $preserveRowNumbers = (bool) ($options['preserve_original_row_numbers'] ?? false);
            $rowNumberKey = (string) ($options['original_row_number_key'] ?? '_mnb_original_row_number');

            foreach ($this->rawRows($options) as $sourceKey => $rawRow) {
                if (!is_array($rawRow)) {
                    $rawRow = [$rawRow];
                }
                $sourceIndex = is_int($sourceKey) ? $sourceKey : $physicalSequence;
                $physicalSequence++;
                $sourceRowNumber = $sourceIndex + 1;
                $sourceRowsSeen = max($sourceRowsSeen, $sourceRowNumber);

                try {
                    $preprocessed = $this->preprocessRows([array_values($rawRow)], $options);
                    $row = $preprocessed[0] ?? [];
                } catch (Throwable $e) {
                    $replacement = $this->handleRowError($e, $sourceRowNumber, $yielded + 1, $rawRow, $options);
                    $notify(new RowState($sourceRowNumber, $yielded + 1, $this->sheetNumber, $rawRow, [['message' => $e->getMessage(), 'type' => $e::class]], true));
                    if ($replacement === null) {
                        continue;
                    }
                    $row = array_values($replacement);
                }

                $notEmpty = self::isNotEmptyRow($row);
                $eligibleDataRow = !$skipEmptyRows || $notEmpty;

                if (!$headerFound) {
                    $wantedHeader = false;
                    if ($headerMode === 'physical') {
                        $wantedHeader = $sourceRowNumber === max(1, (int) $headerRow);
                    } elseif ($headerMode === 'first_non_empty') {
                        $wantedHeader = $notEmpty;
                    } else {
                        if ($eligibleDataRow) {
                            $dataRowPosition++;
                        }
                        $target = $headerRow === true ? 1 : max(1, (int) $headerRow);
                        if ($headerMode === 'legacy') {
                            $wantedHeader = ($eligibleDataRow && $sourceRowNumber === $target)
                                || ($eligibleDataRow && $dataRowPosition === $target);
                        } else {
                            $wantedHeader = $eligibleDataRow && $dataRowPosition === $target;
                        }
                    }

                    if ($eligibleDataRow) {
                        $processedRows++;
                        if ($maxRows !== null && $processedRows > $maxRows) {
                            throw new MnbExcelException('Row limit exceeded. Rows: ' . $processedRows . ', max_rows: ' . $maxRows);
                        }
                    }
                    if ($wantedHeader) {
                        $headers = $this->normalizeHeaders($row, (string) ($options['duplicate_headers'] ?? 'rename'), $options);
                        $headerFound = true;
                    }
                    continue;
                }

                if (!$eligibleDataRow) {
                    continue;
                }
                $processedRows++;
                if ($maxRows !== null && $processedRows > $maxRows) {
                    throw new MnbExcelException('Row limit exceeded. Rows: ' . $processedRows . ', max_rows: ' . $maxRows);
                }

                try {
                    if ($headerRow !== false) {
                        $mapped = $this->mapRowToHeaders($headers, $row, $options, $sourceRowNumber);
                        $normalized = $preserveRowNumbers ? [$rowNumberKey => $sourceRowNumber] + $mapped : $mapped;
                    } else {
                        $normalized = $this->parseListRow($row, $options);
                    }
                    $normalized = $this->applyColumnSelection($normalized, $postReadSelectors, $options);
                } catch (Throwable $e) {
                    $replacement = $this->handleRowError($e, $sourceRowNumber, $yielded + 1, $row, $options);
                    $notify(new RowState($sourceRowNumber, $yielded + 1, $this->sheetNumber, $row, [['message' => $e->getMessage(), 'type' => $e::class]], true));
                    if ($replacement === null) {
                        continue;
                    }
                    $normalized = $replacement;
                }

                if ($dataIndex++ < $offset) {
                    continue;
                }
                if ($limit !== null && $yielded >= $limit) {
                    break;
                }

                $state = new RowState($sourceRowNumber, $yielded + 1, $this->sheetNumber, $normalized);
                $notify($state);
                yield $yielded => $normalized;
                $yielded++;
            }

            if (!$headerFound) {
                throw new MnbExcelException('Header row does not exist for mode ' . $headerMode . '.');
            }
        } finally {
            if (is_callable($progressCallback)) {
                $progressCallback(new ReadProgress(
                    $this->path,
                    $this->sheetNumber,
                    $sourceRowsSeen,
                    $outputRows,
                    $errorRows,
                    microtime(true) - $startedAt,
                    true
                ));
            }
        }
    }

    /** @return \Generator<int,RowState> */
    public function rowStates(array|ReaderOptions $options = []): \Generator
    {
        $values = $options instanceof ReaderOptions ? $options->toArray() : ReaderOptions::fromArray($options)->toArray();
        $queue = [];
        $existing = $values['row_state_callback'] ?? null;
        $values['row_state_callback'] = static function (RowState $state) use (&$queue, $existing): void {
            $queue[] = $state;
            if (is_callable($existing)) {
                $existing($state);
            }
        };

        $stateIndex = 0;
        foreach ($this->rows($values) as $_row) {
            while ($queue !== []) {
                /** @var RowState $state */
                $state = array_shift($queue);
                yield $stateIndex++ => $state;
            }
        }
        while ($queue !== []) {
            /** @var RowState $state */
            $state = array_shift($queue);
            yield $stateIndex++ => $state;
        }
    }

    /** @return \Generator<int,list<array<string,mixed>|list<mixed>>> */
    public function chunks(int $size, array|ReaderOptions $options = []): \Generator
    {
        if ($size < 1) {
            throw new MnbExcelException('Chunk size must be greater than zero.');
        }
        $chunk = [];
        $index = 0;
        foreach ($this->rows($options) as $row) {
            $chunk[] = $row;
            if (count($chunk) >= $size) {
                yield $index++ => $chunk;
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            yield $index => $chunk;
        }
    }

    /**
     * @param callable(array<string,mixed>|list<mixed>,int): (bool|void) $callback
     * @param array<string,mixed> $options
     * @return array{rows:int,stopped:bool}
     */
    public function eachRow(callable $callback, array $options = []): array
    {
        $count = 0;
        $stopped = false;
        foreach ($this->rows($options) as $index => $row) {
            $count++;
            if ($callback($row, $index) === false) {
                $stopped = true;
                break;
            }
        }
        return ['rows' => $count, 'stopped' => $stopped];
    }

    /**
     * @param callable(list<array<string,mixed>|list<mixed>>,array{chunk:int,rows:int}): (bool|void) $callback
     * @param array<string,mixed> $options
     * @return array{rows:int,chunks:int,stopped:bool}
     */
    public function chunk(int $size, callable $callback, array $options = []): array
    {
        if ($size < 1) {
            throw new MnbExcelException('Chunk size must be greater than zero.');
        }

        $chunk = [];
        $rows = 0;
        $chunks = 0;
        $stopped = false;
        foreach ($this->rows($options) as $row) {
            $chunk[] = $row;
            $rows++;
            if (count($chunk) < $size) {
                continue;
            }

            $chunks++;
            if ($callback($chunk, ['chunk' => $chunks, 'rows' => $rows]) === false) {
                $stopped = true;
                $chunk = [];
                break;
            }
            $chunk = [];
        }

        if (!$stopped && $chunk !== []) {
            $chunks++;
            if ($callback($chunk, ['chunk' => $chunks, 'rows' => $rows]) === false) {
                $stopped = true;
            }
        }

        return ['rows' => $rows, 'chunks' => $chunks, 'stopped' => $stopped];
    }

    /** @return array<string,mixed>|list<mixed>|null */
    public function first(array $options = []): ?array
    {
        foreach ($this->rows(array_replace($options, ['limit' => 1])) as $row) {
            return $row;
        }
        return null;
    }

    public function countRows(array $options = []): int
    {
        $count = 0;
        foreach ($this->rows($options) as $_) {
            $count++;
        }
        return $count;
    }

    /**
     * @return list<array<string, mixed>|list<mixed>>
     */
    public function toArray(array|ReaderOptions $options = []): array
    {
        return array_values(iterator_to_array($this->rows($options), false));
    }

    /**
     * Return a professional workbook-level structure instead of only row arrays.
     *
     * Default output shape:
     * [
     *   'status' => 'ok',
     *   'source' => [...],
     *   'sheets' => [
     *      ['sheetname' => 'Sheet 1', 'headers' => [...], 'columns' => [...], 'rows' => [...]],
     *   ],
     *   'summary' => [...],
     *   'warnings' => [],
     *   'errors' => [],
     * ]
     *
     * For the older selected-sheet structure, pass: ['structure' => 'sheet'].
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function toStructuredArray(array $options = []): array
    {
        $options = array_replace($this->defaultOptions, $options);
        $structure = (string) ($options['structure'] ?? $options['scope'] ?? 'workbook');

        if ($structure === 'sheet' || $structure === 'selected_sheet') {
            return $this->toStructuredSheetArray($options);
        }

        return $this->toStructuredWorkbookArray($options);
    }

    /**
     * Return workbook-level structured output with all sheets.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function toStructuredWorkbookArray(array $options = []): array
    {
        $options = array_replace($this->defaultOptions, $options);
        $options['preserve_original_row_numbers'] = $options['preserve_original_row_numbers'] ?? true;

        $inspection = $this->inspect();
        $sheetNames = $this->sheetNames();

        $sheets = [];
        $warnings = (array) ($inspection['warnings'] ?? []);
        $errors = (array) ($inspection['errors'] ?? []);
        $totalSourceRows = 0;
        $totalProcessedRows = 0;
        $totalDataRows = 0;
        $totalColumns = 0;
        $skippedEmptyRows = 0;

        foreach ($sheetNames as $sheetIndex => $sheetName) {
            try {
                $sheetStructure = $this->sheet($sheetName)->toStructuredSheetArray($options);
                $sheetInfo = (array) ($sheetStructure['sheet'] ?? []);
                $sheetSummary = (array) ($sheetStructure['summary'] ?? []);

                $sheets[$sheetIndex] = [
                    'index' => (int) ($sheetInfo['index'] ?? ($sheetIndex + 1)),
                    'sheetname' => (string) ($sheetInfo['name'] ?? $sheetName),
                    'name' => (string) ($sheetInfo['name'] ?? $sheetName),
                    'state' => $sheetInfo['state'] ?? 'visible',
                    'dimension' => $sheetInfo['dimension'] ?? null,
                    'headers' => (array) ($sheetStructure['headers'] ?? []),
                    'columns' => (array) ($sheetStructure['columns'] ?? []),
                    'rows' => (array) ($sheetStructure['rows'] ?? []),
                    'summary' => $sheetSummary,
                    'metadata' => (array) ($sheetStructure['metadata'] ?? []),
                    'warnings' => (array) ($sheetStructure['warnings'] ?? []),
                    'errors' => (array) ($sheetStructure['errors'] ?? []),
                ];

                $totalSourceRows += (int) ($sheetSummary['source_rows'] ?? 0);
                $totalProcessedRows += (int) ($sheetSummary['processed_rows'] ?? 0);
                $totalDataRows += (int) ($sheetSummary['data_rows'] ?? 0);
                $totalColumns += (int) ($sheetSummary['columns'] ?? 0);
                $skippedEmptyRows += (int) ($sheetSummary['skipped_empty_rows'] ?? 0);

                foreach ((array) ($sheetStructure['warnings'] ?? []) as $warning) {
                    $warnings[] = [
                        'sheet' => $sheetName,
                    ] + (is_array($warning) ? $warning : ['message' => (string) $warning]);
                }
                foreach ((array) ($sheetStructure['errors'] ?? []) as $error) {
                    $errors[] = [
                        'sheet' => $sheetName,
                    ] + (is_array($error) ? $error : ['message' => (string) $error]);
                }
            } catch (\Throwable $e) {
                $errors[] = [
                    'sheet' => $sheetName,
                    'code' => 'SHEET_STRUCTURE_FAILED',
                    'message' => $e->getMessage(),
                ];
                if ((bool) ($options['fail_on_sheet_error'] ?? false)) {
                    throw $e;
                }
            }
        }

        return [
            'status' => $errors === [] ? 'ok' : 'partial',
            'source' => [
                'file' => $this->path,
                'format' => $this->readerFormat(),
                'size_bytes' => is_file($this->path) ? (int) filesize($this->path) : null,
            ],
            'sheets' => $sheets,
            'summary' => [
                'sheet_count' => count($sheets),
                'source_rows' => $totalSourceRows,
                'processed_rows' => $totalProcessedRows,
                'data_rows' => $totalDataRows,
                'columns_total' => $totalColumns,
                'skipped_empty_rows' => $skippedEmptyRows,
            ],
            'warnings' => array_values($warnings),
            'errors' => array_values($errors),
        ];
    }

    /**
     * Return selected-sheet structured output.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function toStructuredSheetArray(array $options = []): array
    {
        $options = array_replace($this->defaultOptions, $options);
        $options['preserve_original_row_numbers'] = $options['preserve_original_row_numbers'] ?? true;

        $rawRows = $this->reader->readSheet($this->path, $this->sheetNumber, $options);
        $preprocessedRows = $this->preprocessRows($rawRows, $options);
        $sourceRowCount = count($preprocessedRows);
        $skipEmptyRows = (bool) ($options['skip_empty_rows'] ?? true);
        $skipEmptyColumns = (bool) ($options['skip_empty_columns'] ?? $options['drop_empty_columns'] ?? false);
        $headerRow = $options['header_row'] ?? false;
        $headerKey = $headerRow === false ? null : $this->resolveHeaderKey($preprocessedRows, $headerRow, $options);

        if ($headerRow !== false && ($headerKey === null || !array_key_exists($headerKey, $preprocessedRows))) {
            throw new MnbExcelException('Header row does not exist for mode ' . $this->resolveHeaderRowMode($headerRow, $options) . '.');
        }

        $rows = $preprocessedRows;
        if ($skipEmptyRows) {
            $rows = array_filter(
                $rows,
                static fn(array $row, int $key): bool => $key === $headerKey || self::isNotEmptyRow($row),
                ARRAY_FILTER_USE_BOTH
            );
        }

        if ($skipEmptyColumns) {
            $rows = $this->removeEmptyColumns($rows);
        }

        $maxRows = isset($options['max_rows']) ? (int) $options['max_rows'] : null;
        if ($maxRows !== null && count($rows) > $maxRows) {
            throw new MnbExcelException('Row limit exceeded. Rows: ' . count($rows) . ', max_rows: ' . $maxRows);
        }

        $headers = [];
        $originalHeaders = [];
        $dataRows = [];
        $warnings = [];
        $rowFormat = (string) ($options['row_format'] ?? 'nested');
        $rowNumberKey = (string) ($options['original_row_number_key'] ?? '_mnb_original_row_number');

        if ($headerKey !== null) {
            $originalHeaders = array_values($rows[$headerKey]);
            $headers = $this->normalizeHeaders($originalHeaders, (string) ($options['duplicate_headers'] ?? 'rename'), $options);

            foreach ($rows as $sourceIndex => $row) {
                if ($sourceIndex <= $headerKey) {
                    continue;
                }

                $item = $this->mapRowToHeaders($headers, $row, $options, $sourceIndex + 1);
                if ($rowFormat === 'flat') {
                    $dataRows[] = [$rowNumberKey => $sourceIndex + 1] + $item;
                } else {
                    $dataRows[] = [
                        'index' => count($dataRows),
                        'row_number' => $sourceIndex + 1,
                        'values' => $item,
                    ];
                }
            }

            if (strtolower((string) ($options['extra_columns'] ?? 'ignore')) === 'generate') {
                foreach ($dataRows as $index => $record) {
                    if ($rowFormat === 'flat') {
                        $aligned = [$rowNumberKey => $record[$rowNumberKey]];
                        foreach ($headers as $header) {
                            $aligned[$header] = $record[$header] ?? null;
                        }
                        $dataRows[$index] = $aligned;
                        continue;
                    }

                    $values = [];
                    foreach ($headers as $header) {
                        $values[$header] = $record['values'][$header] ?? null;
                    }
                    $dataRows[$index]['values'] = $values;
                }
            }
        } else {
            $maxColumns = 0;
            foreach ($rows as $row) {
                $maxColumns = max($maxColumns, count($row));
            }

            for ($i = 0; $i < $maxColumns; $i++) {
                $headers[] = 'column_' . ($i + 1);
                $originalHeaders[] = null;
            }

            foreach ($rows as $sourceIndex => $row) {
                $values = [];
                for ($i = 0; $i < $maxColumns; $i++) {
                    $values[$headers[$i]] = $this->parseColumnValue($headers[$i], $row[$i] ?? null, $options);
                }
                $dataRows[] = [
                    'index' => count($dataRows),
                    'row_number' => $sourceIndex + 1,
                    'values' => $values,
                ];
            }
        }

        $columns = [];
        foreach ($headers as $index => $key) {
            $original = $originalHeaders[$index] ?? null;
            $columns[] = [
                'index' => $index + 1,
                'letter' => Coordinate::columnIndexToName($index + 1),
                'header' => $key,
                'original_header' => $original,
                'original' => $original,
                'key' => $key,
                'renamed' => $original !== null && trim((string) $original) !== $key,
                'generated' => $headerKey === null || trim((string) ($original ?? '')) === '',
            ];
        }

        if ($skipEmptyRows && $sourceRowCount !== count($rows)) {
            $warnings[] = [
                'code' => 'EMPTY_ROWS_SKIPPED',
                'message' => ($sourceRowCount - count($rows)) . ' empty row(s) were skipped.',
            ];
        }

        $inspection = $this->inspect();
        $sheetInfo = $this->selectedSheetInfo($inspection);
        $metadata = [];
        if ((bool) ($options['include_cell_metadata'] ?? true)) {
            try {
                $metadata = $this->sheetMetadata($options);
            } catch (\Throwable $e) {
                $warnings[] = [
                    'code' => 'SHEET_METADATA_FAILED',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'status' => 'ok',
            'sheet' => $sheetInfo,
            'headers' => $headers,
            'columns' => $columns,
            'rows' => $dataRows,
            'summary' => [
                'source_rows' => $sourceRowCount,
                'processed_rows' => count($rows),
                'data_rows' => count($dataRows),
                'columns' => count($headers),
                'header_row_number' => $headerKey !== null ? $headerKey + 1 : null,
                'header_row_mode' => $headerRow !== false ? $this->resolveHeaderRowMode($headerRow, $options) : null,
                'skipped_empty_rows' => max(0, $sourceRowCount - count($rows)),
            ],
            'metadata' => $metadata,
            'warnings' => array_merge((array) ($inspection['warnings'] ?? []), $warnings),
            'errors' => (array) ($inspection['errors'] ?? []),
        ];
    }

    /**
     * @param array<string,mixed> $readOptions
     * @param array<string,mixed> $jsonOptions
     */
    public function toStructuredJson(array $readOptions = [], array $jsonOptions = []): string
    {
        return (new JsonWriter())->payloadToString($this->toStructuredArray($readOptions), $jsonOptions);
    }

    /**
     * @param array<string,mixed> $readOptions
     * @param array<string,mixed> $jsonOptions
     */
    public function saveStructuredJson(string $path, array $readOptions = [], array $jsonOptions = []): string
    {
        AtomicFileWriter::writeString($path, $this->toStructuredJson($readOptions, $jsonOptions), ErrorCode::JSON_WRITE_FAILED);
        return $path;
    }


    /**
     * Return structured workbook/sheet output as an XML string without saving.
     * Use this for API responses, browser output, logs, or assigning XML to a variable.
     *
     * @param array<string,mixed> $readOptions
     * @param array<string,mixed> $xmlOptions
     */
    public function toStructuredXml(array $readOptions = [], array $xmlOptions = []): string
    {
        return (new XmlWriter())->payloadToString($this->toStructuredArray($readOptions), $xmlOptions);
    }

    /**
     * Save structured workbook/sheet output as an XML file.
     *
     * @param array<string,mixed> $readOptions
     * @param array<string,mixed> $xmlOptions
     */
    public function saveStructuredXml(string $path, array $readOptions = [], array $xmlOptions = []): string
    {
        AtomicFileWriter::writeString($path, $this->toStructuredXml($readOptions, $xmlOptions), ErrorCode::XML_WRITE_FAILED);
        return $path;
    }



    /**
     * Preview rows before validation/import. Usually pass header_row => true.
     *
     * @param array<string,mixed> $readOptions
     * @param array<string,mixed> $previewOptions
     * @return array<string,mixed>
     */
    public function previewImport(array $readOptions = [], array $previewOptions = []): array
    {
        $rows = $this->toArray($readOptions);
        return (new ImportQualityAnalyzer())->preview($rows, $previewOptions);
    }

    /**
     * Validate rows read from the selected sheet.
     *
     * @param array<string,string> $rules
     * @param array<string,mixed> $readOptions
     * @param array<string,mixed> $validationOptions
     * @return array{valid:list<array<string,mixed>>,failed:list<array{row:int,errors:list<string>,data:array<string,mixed>}>}
     */
    public function validateImport(array $rules, array $readOptions = [], array $validationOptions = []): array
    {
        $rows = $this->toArray($readOptions);
        return (new ArrayValidator())->validate($rows, $rules, $validationOptions);
    }

    /**
     * Detect duplicate rows after reading the selected sheet.
     *
     * @param list<string> $columns
     * @param array<string,mixed> $readOptions
     * @param array<string,mixed> $duplicateOptions
     * @return list<array{key:string,count:int,rows:list<int>}>
     */
    public function duplicateRows(array $columns, array $readOptions = [], array $duplicateOptions = []): array
    {
        $rows = $this->toArray($readOptions);
        return (new ImportQualityAnalyzer())->findDuplicates($rows, $columns, $duplicateOptions);
    }

    /**
     * Build an SQL import plan without inserting data.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function dryRunImportToSql(PDO|array|string|null $pdo, string $table, array $options = []): array
    {
        $options['dry_run'] = true;
        return $this->importToSql($pdo, $table, $options);
    }

    public function importToSql(PDO|array|string|null $pdo, string $table, array $options = []): array
    {
        $rows = $this->toArray($options);
        $connection = DatabaseConnectionFactory::make($pdo, is_array($options['db'] ?? null) ? $options['db'] : []);
        return (new SqlImporter())->importRows($connection, $table, $rows, $options);
    }

    /**
     * Convert selected sheet rows to a JSON string after normal read normalization.
     *
     * @param array<string,mixed> $readOptions
     * @param array<string,mixed> $jsonOptions
     */
    public function toJson(array $readOptions = [], array $jsonOptions = []): string
    {
        return (new JsonWriter())->rowsToString($this->toArray($readOptions), $jsonOptions);
    }

    /**
     * Save selected sheet rows as JSON after normal read normalization.
     *
     * @param array<string,mixed> $readOptions
     * @param array<string,mixed> $jsonOptions
     */
    public function saveJson(string $path, array $readOptions = [], array $jsonOptions = []): string
    {
        (new JsonWriter())->writeRows($this->toArray($readOptions), $path, $jsonOptions);
        return $path;
    }

    /**
     * Convert selected sheet rows to an XML string after normal read normalization.
     *
     * @param array<string,mixed> $readOptions
     * @param array<string,mixed> $xmlOptions
     */
    public function toXml(array $readOptions = [], array $xmlOptions = []): string
    {
        return (new XmlWriter())->rowsToString($this->toArray($readOptions), $xmlOptions);
    }

    /**
     * Save selected sheet rows as XML after normal read normalization.
     *
     * @param array<string,mixed> $readOptions
     * @param array<string,mixed> $xmlOptions
     */
    public function saveXml(string $path, array $readOptions = [], array $xmlOptions = []): string
    {
        (new XmlWriter())->writeRows($this->toArray($readOptions), $path, $xmlOptions);
        return $path;
    }

    /** @return iterable<int,list<mixed>> */
    /**
     * @param array<int|string,mixed> $row
     * @return array<int|string,mixed>|null
     */
    private function handleRowError(Throwable $error, int $sourceRow, int $outputRow, array $row, array $options): ?array
    {
        $policy = RowErrorPolicy::fromMixed($options['row_error_policy'] ?? RowErrorPolicy::Throw);
        $entry = [
            'source_row' => $sourceRow,
            'output_row' => $outputRow,
            'sheet' => $this->sheetNumber,
            'message' => $error->getMessage(),
            'type' => $error::class,
            'row' => $row,
        ];
        if (in_array($policy, [RowErrorPolicy::Collect, RowErrorPolicy::Callback], true)) {
            $this->lastRowErrors[] = $entry;
        }

        if ($policy === RowErrorPolicy::Throw) {
            throw $error;
        }
        if ($policy === RowErrorPolicy::Callback) {
            $handler = $options['row_error_handler'] ?? null;
            if (!is_callable($handler)) {
                throw new MnbExcelException('row_error_policy "callback" requires row_error_handler.');
            }
            $result = $handler($error, new RowState(
                $sourceRow,
                $outputRow,
                $this->sheetNumber,
                $row,
                [['message' => $error->getMessage(), 'type' => $error::class]],
                true
            ));
            return is_array($result) ? $result : null;
        }
        return null;
    }

    private function rawRows(array $options): iterable
    {
        if ($this->reader instanceof IterableReaderInterface) {
            return $this->reader->iterateSheet($this->path, $this->sheetNumber, $options);
        }
        return $this->reader->readSheet($this->path, $this->sheetNumber, $options);
    }

    /**
     * @param array<string,mixed>|list<mixed> $row
     * @param list<int|string> $selectors
     * @return array<string,mixed>|list<mixed>
     */
    private function applyColumnSelection(array $row, array $selectors, array $options): array
    {
        if ($selectors === []) {
            return $row;
        }

        $strict = (bool) ($options['strict_selected_columns'] ?? false);
        $associative = !array_is_list($row);
        $selected = [];

        if ($associative) {
            $keys = array_keys($row);
            foreach ($selectors as $selector) {
                $selectorKey = (string) $selector;
                if (array_key_exists($selectorKey, $row)) {
                    $key = $selectorKey;
                } elseif (is_int($selector) || ctype_digit($selectorKey) || self::isExcelColumnSelector($selectorKey)) {
                    $position = is_int($selector) || ctype_digit($selectorKey)
                        ? max(1, (int) $selector) - 1
                        : Coordinate::columnNameToIndex($selectorKey) - 1;
                    if (!array_key_exists($position, $keys)) {
                        if ($strict) {
                            throw new MnbExcelException('Selected column position does not exist: ' . $selector);
                        }
                        continue;
                    }
                    $key = (string) $keys[$position];
                } else {
                    $key = $selectorKey;
                    if ($strict) {
                        throw new MnbExcelException('Selected column does not exist: ' . $key);
                    }
                    continue;
                }
                $selected[$key] = $row[$key] ?? null;
            }
            return $selected;
        }

        foreach ($selectors as $selector) {
            $index = is_int($selector) || ctype_digit((string) $selector)
                ? max(1, (int) $selector) - 1
                : Coordinate::columnNameToIndex((string) $selector) - 1;
            if (!array_key_exists($index, $row)) {
                if ($strict) {
                    throw new MnbExcelException('Selected column does not exist: ' . $selector);
                }
                continue;
            }
            $selected[] = $row[$index];
        }
        return $selected;
    }

    /** @param array<string,mixed> $options @return list<int|string> */
    private function sessionProjectionSelectors(array $options): array
    {
        $selectors = array_values((array) ($options['columns'] ?? $options['column_projection'] ?? $options['only_columns'] ?? []));
        foreach ($selectors as $selector) {
            if (is_int($selector) || ctype_digit((string) $selector) || self::isExcelColumnSelector((string) $selector)) {
                continue;
            }
            // At least one source-key selector means positional readers must
            // defer the complete union until headers have been mapped.
            return $selectors;
        }
        return [];
    }

    private static function isExcelColumnSelector(string $selector): bool
    {
        if (preg_match('/^[A-Z]{1,3}$/', $selector) !== 1) {
            return false;
        }
        return Coordinate::columnNameToIndex($selector) <= 16384;
    }

    /**
     * @param list<list<mixed>> $rows
     * @return list<array<string, mixed>|list<mixed>>
     */
    private function normalizeRows(array $rows, array $options): array
    {
        $rows = $this->preprocessRows($rows, $options);

        $skipEmptyRows = (bool) ($options['skip_empty_rows'] ?? true);
        $skipEmptyColumns = (bool) ($options['skip_empty_columns'] ?? $options['drop_empty_columns'] ?? false);
        $maxRows = isset($options['max_rows']) ? (int) $options['max_rows'] : null;
        $headerRow = $options['header_row'] ?? false;
        $headerKey = $headerRow === false ? null : $this->resolveHeaderKey($rows, $headerRow, $options);

        if ($headerRow !== false && ($headerKey === null || !array_key_exists($headerKey, $rows))) {
            throw new MnbExcelException('Header row does not exist for mode ' . $this->resolveHeaderRowMode($headerRow, $options) . '.');
        }

        if ($skipEmptyRows) {
            $rows = array_filter(
                $rows,
                static fn(array $row, int $key): bool => $key === $headerKey || self::isNotEmptyRow($row),
                ARRAY_FILTER_USE_BOTH
            );
        }

        if ($skipEmptyColumns) {
            $rows = $this->removeEmptyColumns($rows);
        }

        if ($maxRows !== null && count($rows) > $maxRows) {
            throw new MnbExcelException('Row limit exceeded. Rows: ' . count($rows) . ', max_rows: ' . $maxRows);
        }

        if ($headerRow === false) {
            return array_map(fn (array $row): array => $this->parseListRow($row, $options), array_values($rows));
        }

        if ($rows === []) {
            return [];
        }

        $headers = $this->normalizeHeaders($rows[$headerKey], (string) ($options['duplicate_headers'] ?? 'rename'), $options);
        $result = [];
        $rowNumberKey = (string) ($options['original_row_number_key'] ?? '_mnb_original_row_number');
        $preserveRowNumbers = (bool) ($options['preserve_original_row_numbers'] ?? false);

        foreach ($rows as $sourceIndex => $row) {
            if ($sourceIndex <= $headerKey) {
                continue;
            }
            $mapped = $this->mapRowToHeaders($headers, $row, $options, $sourceIndex + 1);
            $result[] = $preserveRowNumbers ? [$rowNumberKey => $sourceIndex + 1] + $mapped : $mapped;
        }

        if (strtolower((string) ($options['extra_columns'] ?? 'ignore')) === 'generate') {
            foreach ($result as $index => $row) {
                $metadata = [];
                if ($preserveRowNumbers && array_key_exists($rowNumberKey, $row)) {
                    $metadata[$rowNumberKey] = $row[$rowNumberKey];
                }
                $aligned = [];
                foreach ($headers as $header) {
                    $aligned[$header] = $row[$header] ?? null;
                }
                $result[$index] = $metadata + $aligned;
            }
        }

        return $result;
    }

    /**
     * @param array<int,list<mixed>> $rows
     */
    private function resolveHeaderKey(array $rows, mixed $headerRow, array $options = []): ?int
    {
        $mode = $this->resolveHeaderRowMode($headerRow, $options);
        $target = $headerRow === true ? 1 : max(1, (int) $headerRow);

        if ($mode === 'physical') {
            $key = $target - 1;
            return array_key_exists($key, $rows) ? $key : null;
        }

        if ($mode === 'first_non_empty') {
            foreach ($rows as $key => $row) {
                if (self::isNotEmptyRow($row)) {
                    return $key;
                }
            }
            return null;
        }

        $skipEmptyRows = (bool) ($options['skip_empty_rows'] ?? true);
        if ($mode === 'legacy') {
            $physicalKey = $target - 1;
            if (array_key_exists($physicalKey, $rows) && (!$skipEmptyRows || self::isNotEmptyRow($rows[$physicalKey]))) {
                return $physicalKey;
            }
        }

        $position = 0;
        foreach ($rows as $key => $row) {
            if ($skipEmptyRows && !self::isNotEmptyRow($row)) {
                continue;
            }
            $position++;
            if ($position === $target) {
                return $key;
            }
        }

        return null;
    }

    private function resolveHeaderRowMode(mixed $headerRow, array $options): string
    {
        if ($headerRow === false) {
            return 'none';
        }

        $mode = strtolower(trim((string) ($options['header_row_mode'] ?? 'legacy')));
        $mode = match ($mode) {
            'source', 'raw' => 'physical',
            'normalized', 'processed' => 'data',
            'first', 'non_empty', 'first-non-empty' => 'first_non_empty',
            '' => 'legacy',
            default => $mode,
        };

        if (!in_array($mode, ['legacy', 'physical', 'data', 'first_non_empty'], true)) {
            throw new MnbExcelException('header_row_mode must be "physical", "data", "first_non_empty", or "legacy".');
        }
        return $mode;
    }

    /** @param list<list<mixed>> $rows @return list<list<mixed>> */
    private function preprocessRows(array $rows, array $options): array
    {
        $trimValues = (bool) ($options['trim_values'] ?? false);
        $emptyToNull = (bool) ($options['empty_strings_to_null'] ?? false);

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                if (is_string($value) && $trimValues) {
                    $value = trim($value);
                }
                if ($emptyToNull && $value === '') {
                    $value = null;
                }
                $rows[$rowIndex][$columnIndex] = $value;
            }
        }

        return $rows;
    }

    /** @param array<int,list<mixed>> $rows @return array<int,list<mixed>> */
    private function removeEmptyColumns(array $rows): array
    {
        $maxColumns = 0;
        foreach ($rows as $row) {
            $maxColumns = max($maxColumns, count($row));
        }

        $keep = [];
        for ($i = 0; $i < $maxColumns; $i++) {
            foreach ($rows as $row) {
                if (array_key_exists($i, $row) && $row[$i] !== null && trim((string) $row[$i]) !== '') {
                    $keep[] = $i;
                    break;
                }
            }
        }

        $cleaned = [];
        foreach ($rows as $rowIndex => $row) {
            $newRow = [];
            foreach ($keep as $columnIndex) {
                $newRow[] = $row[$columnIndex] ?? null;
            }
            $cleaned[$rowIndex] = $newRow;
        }

        return $cleaned;
    }

    /**
     * @param list<string> $headers
     * @param list<mixed> $row
     * @return array<string,mixed>
     */
    private function mapRowToHeaders(array &$headers, array $row, array $options, int $sourceRowNumber): array
    {
        $extraMode = strtolower((string) ($options['extra_columns'] ?? 'ignore'));
        $missingMode = strtolower((string) ($options['missing_columns'] ?? 'null'));
        $extraMode = match ($extraMode) {
            'discard' => 'ignore',
            'throw' => 'error',
            default => $extraMode,
        };
        $missingMode = match ($missingMode) {
            'pad', 'allow' => 'null',
            'throw' => 'error',
            default => $missingMode,
        };

        if (!in_array($extraMode, ['ignore', 'error', 'generate', 'collect'], true)) {
            throw new MnbExcelException('extra_columns must be "ignore", "error", "generate", or "collect".');
        }
        if (!in_array($missingMode, ['null', 'error'], true)) {
            throw new MnbExcelException('missing_columns must be "null" or "error".');
        }

        $headerCount = count($headers);
        $rowCount = count($row);
        if ($rowCount < $headerCount && $missingMode === 'error') {
            throw MnbExcelException::withCode(
                'Row ' . $sourceRowNumber . ' has ' . $rowCount . ' columns; expected at least ' . $headerCount . '.',
                ErrorCode::FILE_READ_FAILED,
                ['row' => $sourceRowNumber, 'columns' => $rowCount, 'expected_columns' => $headerCount, 'policy' => 'missing_columns']
            );
        }

        if ($rowCount > $headerCount) {
            if ($extraMode === 'error') {
                throw MnbExcelException::withCode(
                    'Row ' . $sourceRowNumber . ' has ' . $rowCount . ' columns; header defines ' . $headerCount . '.',
                    ErrorCode::FILE_READ_FAILED,
                    ['row' => $sourceRowNumber, 'columns' => $rowCount, 'header_columns' => $headerCount, 'policy' => 'extra_columns']
                );
            }

            if ($extraMode === 'generate') {
                $used = array_fill_keys($headers, true);
                $prefix = (string) ($options['extra_column_prefix'] ?? 'column_');
                for ($index = $headerCount; $index < $rowCount; $index++) {
                    $candidate = $this->normalizeHeaderKey($prefix . ($index + 1), $index, $options);
                    $headers[] = $this->allocateUniqueHeader($candidate, $used);
                }
            }
        }

        $item = [];
        foreach ($headers as $index => $header) {
            $item[$header] = $this->parseColumnValue($header, $row[$index] ?? null, $options);
        }

        if ($extraMode === 'collect') {
            $key = (string) ($options['extra_columns_key'] ?? '_extra');
            if ($key === '' || array_key_exists($key, $item)) {
                throw new MnbExcelException('extra_columns_key must be non-empty and must not collide with a header.');
            }
            $extra = [];
            for ($index = $headerCount; $index < $rowCount; $index++) {
                $extra[] = $this->parseColumnValue((string) $index, $row[$index] ?? null, $options);
            }
            $item[$key] = $extra;
        }

        return $item;
    }

    /** @param list<mixed> $row @return list<mixed> */
    private function parseListRow(array $row, array $options): array
    {
        $parsed = [];
        foreach (array_values($row) as $index => $value) {
            $parsed[] = $this->parseColumnValue((string) $index, $value, $options);
        }

        return $parsed;
    }

    private function parseColumnValue(string $column, mixed $value, array $options): mixed
    {
        $integerColumns = array_map('strval', (array) ($options['integer_columns'] ?? []));
        $numberColumns = array_map('strval', (array) ($options['number_columns'] ?? $options['decimal_columns'] ?? []));
        $dateColumns = (array) ($options['date_columns'] ?? []);

        if (in_array($column, $integerColumns, true)) {
            return LocaleNormalizer::parseLocalizedInteger($value, $options);
        }

        if (in_array($column, $numberColumns, true)) {
            return LocaleNormalizer::parseLocalizedNumber($value, $options);
        }

        if (array_key_exists($column, $dateColumns)) {
            return LocaleNormalizer::parseDate($value, (string) $dateColumns[$column], $options);
        }

        if (in_array($column, array_map('strval', $dateColumns), true)) {
            return LocaleNormalizer::parseDate($value, (string) ($options['date_output_format'] ?? 'Y-m-d'), $options);
        }

        return $value;
    }

    /** @param list<mixed> $row */
    private static function isNotEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<mixed> $headers
     * @param array<string,mixed> $options
     * @return list<string>
     */
    private function normalizeHeaders(array $headers, string $duplicateMode, array $options = []): array
    {
        $duplicateMode = strtolower(trim($duplicateMode));
        $duplicateMode = $duplicateMode === 'throw' ? 'error' : $duplicateMode;
        if (!in_array($duplicateMode, ['rename', 'error'], true)) {
            throw new MnbExcelException('duplicate_headers must be "rename" or "error".');
        }

        $result = [];
        $used = [];
        $renameHeaders = array_merge(
            (array) ($options['rename_headers'] ?? []),
            (array) ($options['header_aliases'] ?? []),
            (array) ($options['header_map'] ?? [])
        );

        foreach ($headers as $index => $header) {
            $originalHeader = trim((string) $header);
            $key = $this->normalizeHeaderKey($originalHeader, $index, $options);

            if (array_key_exists($originalHeader, $renameHeaders)) {
                $key = $this->normalizeHeaderKey((string) $renameHeaders[$originalHeader], $index, $options);
            } elseif (array_key_exists($key, $renameHeaders)) {
                $key = $this->normalizeHeaderKey((string) $renameHeaders[$key], $index, $options);
            }

            if (isset($used[$key]) && $duplicateMode === 'error') {
                throw new MnbExcelException('Duplicate header found: ' . $key);
            }

            $result[] = $this->allocateUniqueHeader($key, $used);
        }

        return $result;
    }

    /** @param array<string,true> $used */
    private function allocateUniqueHeader(string $base, array &$used): string
    {
        if (!isset($used[$base])) {
            $used[$base] = true;
            return $base;
        }

        $suffix = 2;
        do {
            $candidate = $base . '_' . $suffix;
            $suffix++;
        } while (isset($used[$candidate]));

        $used[$candidate] = true;
        return $candidate;
    }

    /** @param array<string,mixed> $options */
    private function normalizeHeaderKey(string $header, int $index, array $options): string
    {
        $mode = (string) ($options['header_case'] ?? $options['headers'] ?? 'snake');
        $header = trim($header);

        if ($header === '') {
            return 'column_' . ($index + 1);
        }

        if ($mode === 'preserve' || $mode === 'none') {
            return $header;
        }

        $key = $header;
        if ($mode === 'lower') {
            return $this->lower($key);
        }

        $key = preg_replace('/[^\p{L}\p{N}_]+/u', '_', $key) ?: '';
        $key = trim($key, '_');
        $key = $key !== '' ? $this->lower($key) : 'column_' . ($index + 1);

        if ($mode === 'camel') {
            $parts = array_values(array_filter(explode('_', $key), static fn(string $part): bool => $part !== ''));
            if ($parts === []) {
                return 'column' . ($index + 1);
            }
            $first = array_shift($parts);
            return $first . implode('', array_map(static fn(string $part): string => ucfirst($part), $parts));
        }

        return $key;
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /** @param array<string,mixed> $inspection @return array<string,mixed> */
    private function selectedSheetInfo(array $inspection): array
    {
        $fallback = [
            'selector' => $this->sheetNumber,
            'index' => is_int($this->sheetNumber) ? $this->sheetNumber : null,
            'name' => is_string($this->sheetNumber) ? $this->sheetNumber : null,
        ];

        foreach ((array) ($inspection['sheets'] ?? []) as $sheet) {
            if (!is_array($sheet)) {
                continue;
            }
            if ((is_int($this->sheetNumber) || ctype_digit((string) $this->sheetNumber)) && (int) ($sheet['index'] ?? 0) === (int) $this->sheetNumber) {
                return ['selector' => $this->sheetNumber] + $sheet;
            }
            if (is_string($this->sheetNumber) && (string) ($sheet['name'] ?? '') === $this->sheetNumber) {
                return ['selector' => $this->sheetNumber] + $sheet;
            }
        }

        return $fallback;
    }

    private function readerFormat(): string
    {
        if ($this->reader instanceof XlsxReader) {
            return 'xlsx';
        }
        if ($this->reader instanceof CsvReader) {
            return 'csv';
        }
        if ($this->reader instanceof JsonReader) {
            return 'json';
        }
        if ($this->reader instanceof XmlReader) {
            return 'xml';
        }
        if ($this->reader instanceof OdsReader) {
            return 'ods';
        }
        if ($this->reader instanceof XlsReader) {
            return 'xls';
        }
        return 'unknown';
    }
}
