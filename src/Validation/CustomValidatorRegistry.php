<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Validation;

final class CustomValidatorRegistry
{
    /** @var array<string,callable(mixed,array<string,mixed>,array<string,mixed>):bool|string|null> */
    private static array $rules = [];

    /** @param callable(mixed,array<string,mixed>,array<string,mixed>):bool|string|null $callback */
    public static function register(string $name, callable $callback): void
    {
        self::$rules[$name] = $callback;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::$rules);
    }

    /** @return array{name:string,args:list<string>} */
    public static function parse(string $rule): array
    {
        $pos = strpos($rule, ':');
        if ($pos === false) {
            return ['name' => $rule, 'args' => []];
        }
        $args = array_values(array_filter(array_map('trim', explode(',', substr($rule, $pos + 1))), static fn(string $v): bool => $v !== ''));
        return ['name' => substr($rule, 0, $pos), 'args' => $args];
    }

    /** @param array<string,mixed> $row @param list<array<string,mixed>> $rows */
    public static function check(string $column, mixed $value, string $rule, array $row, array $rows, ?int $rowNumber): ?string
    {
        $parsed = self::parse($rule);
        $name = $parsed['name'];
        if (!isset(self::$rules[$name])) {
            return null;
        }

        $result = (self::$rules[$name])($value, $row, [
            'column' => $column,
            'rule' => $rule,
            'name' => $name,
            'args' => $parsed['args'],
            'rows' => $rows,
            'row_number' => $rowNumber,
        ]);

        if ($result === true || $result === null) {
            return null;
        }
        if ($result === false) {
            return $column . ' is invalid.';
        }
        return (string) $result;
    }

    public static function has(string $rule): bool
    {
        $parsed = self::parse($rule);
        return isset(self::$rules[$parsed['name']]);
    }

    public static function clear(): void
    {
        self::$rules = [];
    }
}
