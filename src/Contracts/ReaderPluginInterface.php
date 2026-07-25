<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Contracts;

interface ReaderPluginInterface
{
    /** @param array<string,mixed> $options */
    public function supports(string $path, array $options = []): bool;

    /** @param array<string,mixed> $options @return iterable<array<string|int,mixed>> */
    public function read(string $path, array $options = []): iterable;
}
