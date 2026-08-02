<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Reader;

/** Optional capability for the unified metadata schema. */
interface MetadataReaderInterface extends ReaderInterface
{
    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function metaInfo(string $path, array $options = []): array;
}
