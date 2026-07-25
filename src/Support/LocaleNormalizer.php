<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Support;

/**
 * Small locale helper for CSV/array imports where decimal and date formats differ.
 */
final class LocaleNormalizer
{
    /** @return array{decimal_separator:string,thousands_separator:string,date_input_formats:list<string>} */
    public static function options(array $options = []): array
    {
        $locale = strtolower(str_replace('-', '_', (string) ($options['locale'] ?? 'en_US')));

        $presets = [
            'en_us' => ['decimal_separator' => '.', 'thousands_separator' => ',', 'date_input_formats' => ['Y-m-d', 'm/d/Y', 'm/d/y', 'Y-m-d H:i:s']],
            'en_in' => ['decimal_separator' => '.', 'thousands_separator' => ',', 'date_input_formats' => ['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y-m-d H:i:s']],
            'hi_in' => ['decimal_separator' => '.', 'thousands_separator' => ',', 'date_input_formats' => ['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y-m-d H:i:s']],
            'en_gb' => ['decimal_separator' => '.', 'thousands_separator' => ',', 'date_input_formats' => ['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y-m-d H:i:s']],
            'de_de' => ['decimal_separator' => ',', 'thousands_separator' => '.', 'date_input_formats' => ['Y-m-d', 'd.m.Y', 'd.m.y', 'd/m/Y']],
            'fr_fr' => ['decimal_separator' => ',', 'thousands_separator' => ' ', 'date_input_formats' => ['Y-m-d', 'd/m/Y', 'd.m.Y']],
        ];

        $resolved = $presets[$locale] ?? $presets['en_us'];
        if (array_key_exists('decimal_separator', $options)) {
            $resolved['decimal_separator'] = (string) $options['decimal_separator'];
        }
        if (array_key_exists('thousands_separator', $options)) {
            $resolved['thousands_separator'] = (string) $options['thousands_separator'];
        }
        if (isset($options['date_input_formats']) && is_array($options['date_input_formats'])) {
            $resolved['date_input_formats'] = array_values(array_map('strval', $options['date_input_formats']));
        }

        return $resolved;
    }

    public static function parseLocalizedNumber(mixed $value, array $options = []): mixed
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return self::invalidCast($value, $options, 'number');
        }

        $raw = trim($value);
        if ($raw === '') {
            return $value;
        }

        $canonical = self::localizedNumericString($raw, $options);
        if ($canonical === null) {
            return self::invalidCast($value, $options, 'number');
        }

        return self::parseCanonicalNumber($canonical, $options, false, $value);
    }

    public static function parseLocalizedInteger(mixed $value, array $options = []): mixed
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            if (!is_finite($value) || $value > PHP_INT_MAX || $value < PHP_INT_MIN) {
                return self::bigIntegerResult(sprintf('%.0f', $value), $options);
            }
            return (int) round($value);
        }
        if (!is_string($value)) {
            return self::invalidCast($value, $options, 'integer');
        }

        $raw = trim($value);
        if ($raw === '') {
            return $value;
        }

        $canonical = self::localizedNumericString($raw, $options);
        if ($canonical === null) {
            return self::invalidCast($value, $options, 'integer');
        }

        return self::parseCanonicalNumber($canonical, $options, true, $value);
    }

    /**
     * Parse an already-normalized numeric string without silently overflowing PHP integers.
     *
     * @param mixed $originalValue Value returned when invalid_cast=preserve.
     */
    public static function parseCanonicalNumber(
        string $value,
        array $options = [],
        bool $integerOnly = false,
        mixed $originalValue = null,
        bool $preserveLeadingZeros = false
    ): mixed {
        $canonical = trim($value);
        $originalValue ??= $value;

        if (preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/', $canonical) !== 1) {
            return self::invalidCast($originalValue, $options, $integerOnly ? 'integer' : 'number');
        }

        if ($preserveLeadingZeros && preg_match('/^[+-]?0\d+$/', $canonical) === 1) {
            return $canonical;
        }

        $isPlainInteger = preg_match('/^[+-]?\d+$/', $canonical) === 1;
        if ($isPlainInteger) {
            return self::parseIntegerString($canonical, $options);
        }

        if ($integerOnly) {
            if (preg_match('/^([+-]?\d+)\.0+$/', $canonical, $match) === 1) {
                return self::parseIntegerString($match[1], $options);
            }

            $number = (float) $canonical;
            if (!is_finite($number) || $number > PHP_INT_MAX || $number < PHP_INT_MIN) {
                return self::bigIntegerResult($canonical, $options);
            }
            return (int) round($number);
        }

        $precisionMode = strtolower((string) ($options['numeric_precision'] ?? 'safe'));
        if (!in_array($precisionMode, ['safe', 'native', 'string'], true)) {
            throw new MnbExcelException('numeric_precision must be "safe", "native", or "string".');
        }
        if ($precisionMode === 'string') {
            return $canonical;
        }

        if ($precisionMode === 'safe') {
            $maxDigits = max(1, (int) ($options['max_safe_decimal_digits'] ?? 15));
            if (self::significantDigits($canonical) > $maxDigits) {
                return $canonical;
            }
        }

        $number = (float) $canonical;
        if (!is_finite($number)) {
            return self::invalidCast($originalValue, $options, 'number');
        }
        return $number;
    }

    public static function parseDate(mixed $value, string $outputFormat = 'Y-m-d', array $options = []): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format($outputFormat);
        }
        if (!is_string($value)) {
            return $value;
        }

        $raw = trim($value);
        if ($raw === '') {
            return $value;
        }

        foreach (self::options($options)['date_input_formats'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $raw);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date instanceof \DateTimeImmutable && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))) {
                return $date->format($outputFormat);
            }
        }

        $timestamp = strtotime($raw);
        if ($timestamp !== false) {
            return date($outputFormat, $timestamp);
        }

        return $value;
    }

    private static function localizedNumericString(string $raw, array $options): ?string
    {
        $locale = self::options($options);
        $number = str_replace(["\xc2\xa0", ' '], '', $raw);
        if ($locale['thousands_separator'] !== '') {
            $number = str_replace($locale['thousands_separator'], '', $number);
        }
        if ($locale['decimal_separator'] !== '.') {
            $number = str_replace($locale['decimal_separator'], '.', $number);
        }

        return preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/', $number) === 1
            ? $number
            : null;
    }

    private static function parseIntegerString(string $value, array $options): int|float|string
    {
        $precisionMode = strtolower((string) ($options['numeric_precision'] ?? 'safe'));
        if (!in_array($precisionMode, ['safe', 'native', 'string'], true)) {
            throw new MnbExcelException('numeric_precision must be "safe", "native", or "string".');
        }
        if ($precisionMode === 'string') {
            return $value;
        }

        if (self::fitsPhpInteger($value)) {
            return (int) $value;
        }

        if ($precisionMode === 'native') {
            return (int) $value;
        }

        return self::bigIntegerResult($value, $options);
    }

    private static function bigIntegerResult(string $value, array $options): int|float|string
    {
        $mode = strtolower((string) ($options['big_integer_mode'] ?? 'string'));
        return match ($mode) {
            'string', 'preserve' => $value,
            'float' => (float) $value,
            'error', 'throw' => throw new MnbExcelException('Integer value exceeds the PHP integer range: ' . $value),
            default => throw new MnbExcelException('big_integer_mode must be "string", "float", or "error".'),
        };
    }

    private static function invalidCast(mixed $value, array $options, string $target): mixed
    {
        $mode = strtolower((string) ($options['invalid_cast'] ?? 'preserve'));
        return match ($mode) {
            'preserve', 'keep' => $value,
            'null' => null,
            'error', 'throw' => throw new MnbExcelException('Unable to cast value to ' . $target . ': ' . self::displayValue($value)),
            default => throw new MnbExcelException('invalid_cast must be "preserve", "null", or "error".'),
        };
    }

    private static function fitsPhpInteger(string $value): bool
    {
        $negative = str_starts_with($value, '-');
        $digits = ltrim($value, '+-');
        $digits = ltrim($digits, '0');
        $digits = $digits === '' ? '0' : $digits;
        $limit = $negative ? ltrim((string) PHP_INT_MIN, '-') : (string) PHP_INT_MAX;

        if (strlen($digits) !== strlen($limit)) {
            return strlen($digits) < strlen($limit);
        }
        return strcmp($digits, $limit) <= 0;
    }

    private static function significantDigits(string $value): int
    {
        $mantissa = preg_split('/[eE]/', ltrim($value, '+-'), 2)[0] ?? $value;
        $digits = str_replace('.', '', $mantissa);
        $digits = ltrim($digits, '0');
        return max(1, strlen($digits));
    }

    private static function displayValue(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return var_export($value, true);
        }
        return get_debug_type($value);
    }
}
