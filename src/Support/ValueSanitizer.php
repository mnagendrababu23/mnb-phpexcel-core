<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Support;

use Mnb\PHPExcel\Core\CellValue;

final class ValueSanitizer
{
    public static function escapeFormulaLikeText(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        if (self::isFormulaLikeText($value)) {
            return str_starts_with($value, "'") ? $value : "'" . $value;
        }

        return $value;
    }

    public static function isFormulaLikeText(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        // Excel/CSV injection can hide after BOM or whitespace. Keep this strict by default.
        $check = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        $check = ltrim($check, " \t\r\n\v\f");
        if ($check === '') {
            return false;
        }
        if (str_starts_with($check, "'")) {
            return false;
        }

        return in_array($check[0], ['=', '+', '-', '@'], true);
    }

    public static function sanitizeFormulaLikeText(mixed $value, string $policy = 'escape'): mixed
    {
        if ($value instanceof CellValue) {
            $value = $value->displayValue();
        }
        if (!is_string($value) || $value === '') {
            return $value;
        }

        $policy = strtolower(trim($policy));
        if ($policy === 'none') {
            return $value;
        }

        $check = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        $leading = strlen($check) - strlen(ltrim($check, " \t\r\n\v\f"));
        $prefix = $leading > 0 ? substr($check, 0, $leading) : '';
        $body = ltrim($check, " \t\r\n\v\f");
        $alreadyEscaped = str_starts_with($body, "'");
        if ($alreadyEscaped) {
            $body = substr($body, 1);
        }

        if ($body === '' || !in_array($body[0], ['=', '+', '-', '@'], true)) {
            return $value;
        }

        return match ($policy) {
            'escape' => $alreadyEscaped ? $value : $prefix . "'" . $body,
            'tab_escape' => "\t" . ($alreadyEscaped ? $body : $value),
            'strip' => $prefix . ltrim($body, '=+-@'),
            'block' => throw MnbExcelException::withCode('CSV/formula injection risk detected in value: ' . substr($body, 0, 80), ErrorCode::SECURITY_BLOCKED),
            default => throw MnbExcelException::withCode('Unknown formula/CSV injection policy: ' . $policy, ErrorCode::INVALID_ARGUMENT),
        };
    }

    public static function normalizeScalar(mixed $value): string|int|float|bool|null|CellValue
    {
        if ($value instanceof CellValue) {
            return $value;
        }
        if ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    public static function sanitizeCellText(string $value, array $options = []): string
    {
        $controlPolicy = strtolower((string) ($options['control_char_policy'] ?? 'strip'));
        if (self::containsInvalidXmlCharacters($value)) {
            if ($controlPolicy === 'error' || $controlPolicy === 'block') {
                throw MnbExcelException::withCode('Cell text contains invalid XML control characters.', ErrorCode::SECURITY_BLOCKED);
            }
            if ($controlPolicy !== 'strip') {
                throw MnbExcelException::withCode('Unknown control character policy: ' . $controlPolicy, ErrorCode::INVALID_ARGUMENT);
            }
            $value = self::stripInvalidXmlCharacters($value);
        }

        $maxLength = (int) ($options['max_text_length'] ?? 32767);
        if ($maxLength > 0 && self::textLength($value) > $maxLength) {
            $longPolicy = strtolower((string) ($options['long_text_policy'] ?? 'truncate'));
            if ($longPolicy === 'error' || $longPolicy === 'block') {
                throw MnbExcelException::withCode('Cell text exceeds maximum length of ' . $maxLength . ' characters.', ErrorCode::SECURITY_BLOCKED);
            }
            if ($longPolicy !== 'truncate') {
                throw MnbExcelException::withCode('Unknown long text policy: ' . $longPolicy, ErrorCode::INVALID_ARGUMENT);
            }
            $value = self::substring($value, 0, $maxLength);
        }

        return $value;
    }

    public static function containsInvalidXmlCharacters(string $value): bool
    {
        return preg_match('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', $value) === 1;
    }

    public static function stripInvalidXmlCharacters(string $value): string
    {
        return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value) ?? '';
    }

    public static function isLargeIntegerString(string $value, int $maxDigits = 15): bool
    {
        $clean = trim($value);
        if ($clean === '') {
            return false;
        }
        if (preg_match('/^[+-]?\d+$/', $clean) !== 1) {
            return false;
        }
        $digits = ltrim($clean, '+-');
        $digits = ltrim($digits, '0');
        return strlen($digits) > $maxDigits;
    }

    public static function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    public static function substring(string $value, int $start, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, $start, $length, 'UTF-8') : substr($value, $start, $length);
    }
}
