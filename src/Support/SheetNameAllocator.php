<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Support;

/**
 * Produces valid, unique Excel worksheet names.
 *
 * Excel worksheet names are limited to 31 Unicode characters, cannot contain
 * \\ / ? * [ ] :, and are compared case-insensitively for uniqueness.
 */
final class SheetNameAllocator
{
    /** @var array<string,true> */
    private array $used = [];

    public function allocate(string $name): string
    {
        $base = self::sanitize($name);
        $candidate = $base;
        $suffix = 2;

        while (isset($this->used[self::comparisonKey($candidate)])) {
            $suffixText = ' (' . $suffix . ')';
            $candidate = self::truncate($base, max(1, 31 - self::length($suffixText))) . $suffixText;
            $suffix++;
        }

        $this->used[self::comparisonKey($candidate)] = true;
        return $candidate;
    }

    public static function sanitize(string $name): string
    {
        $name = trim(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $name) ?? $name;
        $name = trim($name, " \t\n\r\0\x0B'");
        $name = $name === '' ? 'Sheet' : $name;

        return self::truncate($name, 31);
    }

    private static function comparisonKey(string $name): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($name, 'UTF-8');
        }

        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
            if (is_string($ascii) && $ascii !== '') {
                return strtolower($ascii);
            }
        }

        return strtolower($name);
    }

    private static function length(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }
        if (preg_match('//u', $value) === 1) {
            return preg_match_all('/./us', $value, $unused) ?: 0;
        }
        return strlen($value);
    }

    private static function truncate(string $value, int $length): string
    {
        if ($length <= 0) {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }
        if (preg_match('//u', $value) === 1) {
            preg_match_all('/./us', $value, $matches);
            return implode('', array_slice($matches[0] ?? [], 0, $length));
        }
        return substr($value, 0, $length);
    }
}
