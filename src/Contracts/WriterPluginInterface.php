<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Contracts;

interface WriterPluginInterface
{
    /** @param array<string,mixed> $options */
    public function supports(string $format, array $options = []): bool;

    /** @param iterable<array<string|int,mixed>> $rows @param array<string,mixed> $options */
    public function write(iterable $rows, string $path, array $options = []): string;
}
