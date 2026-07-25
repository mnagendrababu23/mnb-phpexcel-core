<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Reader;

use Mnb\PHPExcel\Support\Coordinate;
use Mnb\PHPExcel\Support\MnbExcelException;

/** Source-level projection used before row normalization and header mapping. */
final class ColumnProjection
{
    /** @param array<int,true> $indexes @param array<string,true> $keys */
    private function __construct(
        private readonly array $indexes,
        private readonly array $keys,
        private readonly bool $compact,
        private readonly ?int $startIndex,
        private readonly ?int $endIndex,
    ) {
    }

    /** @param array<string,mixed> $options */
    public static function fromOptions(array $options): self
    {
        $selectors = $options['column_projection'] ?? $options['columns'] ?? $options['only_columns'] ?? [];
        $indexes = [];
        $keys = [];
        foreach ((array) $selectors as $selector) {
            if (is_int($selector) || ctype_digit((string) $selector)) {
                $index = (int) $selector;
                if ($index < 1) {
                    throw new MnbExcelException('Column projection indexes are 1-based and must be greater than zero.');
                }
                $indexes[$index] = true;
                continue;
            }

            $value = trim((string) $selector);
            // Uppercase A..XFD is positional. Other strings are associative
            // source keys, so ordinary names such as `name` are not mistaken
            // for Excel column letters.
            if ($value !== '' && preg_match('/^[A-Z]{1,3}$/', $value) === 1) {
                $index = Coordinate::columnNameToIndex($value);
                if ($index <= 16384) {
                    $indexes[$index] = true;
                    continue;
                }
            }
            if ($value !== '') {
                $keys[$value] = true;
            }
        }

        $startIndex = self::columnToIndex($options['start_column'] ?? null);
        $endIndex = self::columnToIndex($options['end_column'] ?? null);
        if ($startIndex !== null && $endIndex !== null && $endIndex < $startIndex) {
            throw new MnbExcelException('end_column must not be before start_column.');
        }

        return new self(
            $indexes,
            $keys,
            (bool) ($options['compact_selected_columns'] ?? true),
            $startIndex,
            $endIndex,
        );
    }

    public function active(): bool
    {
        return $this->indexes !== [] || $this->keys !== [] || $this->startIndex !== null || $this->endIndex !== null;
    }

    public function includesIndex(int $oneBasedIndex): bool
    {
        if ($this->startIndex !== null && $oneBasedIndex < $this->startIndex) {
            return false;
        }
        if ($this->endIndex !== null && $oneBasedIndex > $this->endIndex) {
            return false;
        }
        // Named selectors require header/schema information. Positional source
        // readers must preserve candidate columns until ReadSession can map
        // those names. Pure positional projections are still pushed down.
        if ($this->keys !== []) {
            return true;
        }
        return $this->indexes === [] || isset($this->indexes[$oneBasedIndex]);
    }

    public function includesKey(string $key): bool
    {
        return $this->keys === [] || isset($this->keys[$key]);
    }

    /** @param array<int|string,mixed> $row @return array<int|string,mixed> */
    public function project(array $row): array
    {
        if (!$this->active()) {
            return $row;
        }

        $result = [];
        $isList = array_is_list($row);
        foreach ($row as $key => $value) {
            $include = is_int($key)
                ? $this->includesIndex($key + 1)
                : $this->includesKey((string) $key);
            if (!$include) {
                continue;
            }
            if ($isList && $this->compact) {
                $result[] = $value;
            } else {
                $result[$key] = $value;
            }
        }
        if ($isList && !$this->compact && $result !== []) {
            $max = max(array_keys($result));
            return array_replace(array_fill(0, $max + 1, null), $result);
        }
        return $result;
    }

    /** @return array<int,true> */
    public function indexes(): array
    {
        return $this->indexes;
    }

    public function compact(): bool
    {
        return $this->compact;
    }

    private static function columnToIndex(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || ctype_digit((string) $value)) {
            $index = (int) $value;
        } else {
            $index = Coordinate::columnNameToIndex((string) $value);
        }
        if ($index < 1) {
            throw new MnbExcelException('Column ranges are 1-based and must be greater than zero.');
        }
        return $index;
    }
}
