<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Reader;

/**
 * Optional reader contract for forward-only row iteration.
 *
 * Implementations may still buffer format-level metadata, but they should not
 * require the full worksheet row set to be materialized before yielding rows.
 */
interface IterableReaderInterface extends ReaderInterface
{
    /**
     * @param array<string,mixed> $options
     * @return iterable<int,list<mixed>> Row keys are zero-based source row numbers when available.
     */
    public function iterateSheet(string $path, int|string $sheet = 1, array $options = []): iterable;
}
