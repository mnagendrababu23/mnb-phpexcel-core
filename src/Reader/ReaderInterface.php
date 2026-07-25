<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Reader;

interface ReaderInterface
{
    /**
     * @param int|string $sheet Sheet index starting at 1, or sheet name when supported by the reader.
     * @return list<list<mixed>>
     */
    public function readSheet(string $path, int|string $sheet = 1, array $options = []): array;
}
