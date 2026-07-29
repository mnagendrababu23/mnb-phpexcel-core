<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Reader;

/** Optional capability for format-specific workbook inspection. */
interface InspectableReaderInterface extends ReaderInterface
{
    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function inspect(string $path, array $options = []): array;
}
