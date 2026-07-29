<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Reader;

/** Optional capability for formats that can expose workbook sheet names. */
interface SheetNamesReaderInterface extends ReaderInterface
{
    /** @param array<string,mixed> $options @return list<string> */
    public function sheetNames(string $path, array $options = []): array;
}
