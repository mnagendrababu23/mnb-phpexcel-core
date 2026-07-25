<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Core;

use DateTimeInterface;
use Mnb\PHPExcel\Support\MnbExcelException;

/**
 * Explicit typed cell value for array-first exports.
 *
 * This lets MNB PHPExcel keep ordinary user strings safe while still allowing
 * developers to intentionally write numbers, booleans, dates, blanks, errors,
 * and formulas without using fragile string conventions.
 */
final class CellValue
{
    public const TYPE_TEXT = 'text';
    public const TYPE_NUMBER = 'number';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_DATE = 'date';
    public const TYPE_FORMULA = 'formula';
    public const TYPE_ERROR = 'error';
    public const TYPE_BLANK = 'blank';

    /** @param array<string,mixed> $options */
    private function __construct(
        private readonly string $type,
        private readonly mixed $value = null,
        private readonly mixed $cachedValue = null,
        private readonly array $options = []
    ) {
    }

    public static function text(mixed $value): self
    {
        return new self(self::TYPE_TEXT, $value);
    }

    public static function number(int|float|string $value): self
    {
        if (!is_numeric($value)) {
            throw new MnbExcelException('Number cell value must be numeric.');
        }

        // Keep numeric strings as strings so XLSX serialization can preserve the
        // developer-supplied precision instead of forcing an early PHP float cast.
        // Excel itself still has its normal numeric precision limits; long IDs
        // should be written with CellValue::text()/MnbExcel::text().
        return new self(self::TYPE_NUMBER, is_string($value) ? trim($value) : $value);
    }

    public static function bool(bool $value): self
    {
        return new self(self::TYPE_BOOLEAN, $value);
    }

    /** @param array{format?:string} $options */
    public static function date(DateTimeInterface|string $value, array $options = []): self
    {
        return new self(self::TYPE_DATE, $value, null, $options);
    }

    /** @param array<string,mixed> $options */
    public static function formula(string $formula, mixed $cachedValue = null, array $options = []): self
    {
        $formula = trim($formula);
        if ($formula === '') {
            throw new MnbExcelException('Formula cannot be empty.');
        }
        if (str_starts_with($formula, '=')) {
            $formula = substr($formula, 1);
        }

        return new self(self::TYPE_FORMULA, $formula, $cachedValue, $options);
    }

    public static function error(string $code): self
    {
        $code = strtoupper(trim($code));
        if (!in_array($code, ['#NULL!', '#DIV/0!', '#VALUE!', '#REF!', '#NAME?', '#NUM!', '#N/A'], true)) {
            throw new MnbExcelException('Unsupported Excel error code: ' . $code);
        }

        return new self(self::TYPE_ERROR, $code);
    }

    public static function blank(): self
    {
        return new self(self::TYPE_BLANK);
    }

    /** @param array<string,mixed> $definition */
    public static function fromArray(array $definition): self
    {
        $type = strtolower((string) ($definition['type'] ?? 'text'));
        $value = $definition['value'] ?? null;

        return match ($type) {
            self::TYPE_TEXT, 'string' => self::text($value),
            self::TYPE_NUMBER, 'numeric', 'int', 'integer', 'float', 'decimal' => self::number($value),
            self::TYPE_BOOLEAN, 'bool' => self::bool((bool) $value),
            self::TYPE_DATE, 'datetime' => self::date((string) $value, $definition),
            self::TYPE_FORMULA => self::formula((string) ($definition['formula'] ?? $value ?? ''), $definition['cached_value'] ?? $definition['cachedValue'] ?? null, $definition),
            self::TYPE_ERROR => self::error((string) $value),
            self::TYPE_BLANK, 'null' => self::blank(),
            default => throw new MnbExcelException('Unsupported cell value type: ' . $type),
        };
    }

    public function type(): string
    {
        return $this->type;
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function cachedValue(): mixed
    {
        return $this->cachedValue;
    }

    /** @return array<string,mixed> */
    public function options(): array
    {
        return $this->options;
    }

    public function displayValue(): string|int|float|bool|null
    {
        if ($this->type === self::TYPE_BLANK) {
            return null;
        }
        if ($this->type === self::TYPE_FORMULA) {
            return $this->cachedValue ?? '=' . (string) $this->value;
        }
        if ($this->type === self::TYPE_DATE && $this->value instanceof DateTimeInterface) {
            return $this->value->format((string) ($this->options['format'] ?? 'Y-m-d H:i:s'));
        }

        return is_scalar($this->value) || $this->value === null ? $this->value : (string) $this->value;
    }
}
