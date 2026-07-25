<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Application;

/**
 * Ordered row transformations for application imports.
 */
final class RowTransformerPipeline
{
    /** @var array<string,callable(array<string|int,mixed>, array<string,mixed>):array<string|int,mixed>> */
    private static array $named = [];

    /** @param callable(array<string|int,mixed>, array<string,mixed>):array<string|int,mixed> $transformer */
    public static function register(string $name, callable $transformer): void
    {
        self::$named[$name] = $transformer;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::$named);
    }

    /** @param array<string|int,mixed> $row @param list<callable|string> $transformers @param array<string,mixed> $context @return array<string|int,mixed> */
    public static function apply(array $row, array $transformers = [], array $context = []): array
    {
        foreach ($transformers as $transformer) {
            if (is_string($transformer)) {
                if (!isset(self::$named[$transformer])) {
                    continue;
                }
                $row = (self::$named[$transformer])($row, $context);
                continue;
            }
            if (is_callable($transformer)) {
                $row = $transformer($row, $context);
            }
        }
        return $row;
    }

    /** @param list<array<string|int,mixed>> $rows @param list<callable|string> $transformers @param array<string,mixed> $context @return list<array<string|int,mixed>> */
    public static function applyRows(array $rows, array $transformers = [], array $context = []): array
    {
        if ($transformers === []) {
            return $rows;
        }
        $out = [];
        foreach ($rows as $index => $row) {
            $out[] = self::apply($row, $transformers, array_merge($context, ['index' => $index]));
        }
        return $out;
    }

    public static function clear(): void
    {
        self::$named = [];
    }
}
