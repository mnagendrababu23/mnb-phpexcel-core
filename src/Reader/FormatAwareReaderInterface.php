<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Reader;

/** Optional capability exposing the normalized format name handled by a reader. */
interface FormatAwareReaderInterface extends ReaderInterface
{
    public function format(): string;
}
