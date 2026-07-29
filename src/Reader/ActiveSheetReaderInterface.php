<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Reader;

/** Optional capability for formats that expose the workbook's active worksheet. */
interface ActiveSheetReaderInterface extends ReaderInterface
{
    /**
     * Return the active worksheet using a 1-based index.
     *
     * @param array<string,mixed> $options
     * @return array{index:int,name:string}
     */
    public function activeSheet(string $path, array $options = []): array;
}
