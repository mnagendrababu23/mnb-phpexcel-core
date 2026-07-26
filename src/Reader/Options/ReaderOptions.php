<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Reader\Options;

/**
 * Immutable, typed read configuration.
 *
 * Legacy array keys remain accepted by readers. This object normalizes the
 * stable public vocabulary and emits compatible aliases for older releases.
 */
final class ReaderOptions
{
    /** @param array<string,mixed> $values */
    private function __construct(private readonly array $values)
    {
    }

    /** @param array<string,mixed> $values */
    public static function fromArray(array $values = []): self
    {
        $normalized = $values;

        if (isset($normalized['range']) && is_array($normalized['range'])) {
            $range = $normalized['range'];
            $normalized['start_row'] = $range['start_row'] ?? $range['row_start'] ?? $normalized['start_row'] ?? null;
            $normalized['end_row'] = $range['end_row'] ?? $range['row_end'] ?? $normalized['end_row'] ?? null;
            $normalized['start_column'] = $range['start_column'] ?? $range['column_start'] ?? $normalized['start_column'] ?? null;
            $normalized['end_column'] = $range['end_column'] ?? $range['column_end'] ?? $normalized['end_column'] ?? null;
        }

        // Stable names take precedence; aliases are emitted for old readers.
        if (array_key_exists('columns', $normalized)) {
            $normalized['only_columns'] = $normalized['columns'];
            $normalized['column_projection'] = $normalized['columns'];
        } elseif (array_key_exists('column_projection', $normalized)) {
            $normalized['only_columns'] = $normalized['column_projection'];
            $normalized['columns'] = $normalized['column_projection'];
        } elseif (array_key_exists('only_columns', $normalized)) {
            $normalized['columns'] = $normalized['only_columns'];
            $normalized['column_projection'] = $normalized['only_columns'];
        }

        if (isset($normalized['read_mode'])) {
            $normalized['read_mode'] = ReadMode::fromMixed($normalized['read_mode'])->value;
        }
        if (isset($normalized['row_error_policy'])) {
            $normalized['row_error_policy'] = RowErrorPolicy::fromMixed($normalized['row_error_policy'])->value;
        }

        foreach (['start_row', 'end_row', 'start_column', 'end_column'] as $key) {
            if (isset($normalized[$key])) {
                $normalized[$key] = max(1, (int) $normalized[$key]);
            }
        }

        return new self(array_filter($normalized, static fn(mixed $value): bool => $value !== null));
    }

    public static function defaults(): self
    {
        return new self([]);
    }

    public function withRange(?int $startRow = null, ?int $endRow = null, int|string|null $startColumn = null, int|string|null $endColumn = null): self
    {
        return $this->with([
            'start_row' => $startRow,
            'end_row' => $endRow,
            'start_column' => $startColumn,
            'end_column' => $endColumn,
        ]);
    }

    /** @param list<int|string> $columns */
    public function withColumns(array $columns, bool $compact = true): self
    {
        return $this->with([
            'columns' => array_values($columns),
            'compact_selected_columns' => $compact,
        ]);
    }

    public function withMode(ReadMode|string $mode): self
    {
        return $this->with(['read_mode' => ReadMode::fromMixed($mode)->value]);
    }

    /** Detect the most likely physical header row from the first source rows. */
    public function withAutoHeader(int $sampleRows = 25, float $minimumConfidence = 0.35): self
    {
        return $this->with([
            'header_row' => 'auto',
            'header_row_mode' => 'auto',
            'header_detection_rows' => max(1, $sampleRows),
            'header_min_confidence' => max(0.0, min(1.0, $minimumConfidence)),
        ]);
    }

    /** Read formulas as expressions, cached values, or typed expression-plus-cache objects. */
    /** Supply the password used to open an encrypted XLSX workbook. */
    public function withPassword(string $password): self
    {
        if ($password === '') {
            throw new \InvalidArgumentException('Workbook password cannot be empty.');
        }
        return $this->with(['password' => $password]);
    }

    public function withFormulaMode(string $mode): self
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['formula', 'cached_value', 'both'], true)) {
            throw new \InvalidArgumentException('Formula mode must be formula, cached_value, or both.');
        }
        return $this->with(['formula_cells' => $mode]);
    }

    public function withRowErrorPolicy(RowErrorPolicy|string $policy, ?callable $handler = null): self
    {
        $values = ['row_error_policy' => RowErrorPolicy::fromMixed($policy)->value];
        if ($handler !== null) {
            $values['row_error_handler'] = $handler;
        }
        return $this->with($values);
    }

    public function withProgress(callable $callback, int $everyRows = 1000): self
    {
        return $this->with([
            'progress' => $callback,
            'progress_every_rows' => max(1, $everyRows),
        ]);
    }

    /** @param array<string,mixed> $values */
    public function with(array $values): self
    {
        return self::fromArray(array_replace($this->values, $values));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->values;
    }
}
