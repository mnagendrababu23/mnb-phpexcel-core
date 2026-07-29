<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Reader;

/** Native XLS capability contract retained as a format-specific marker. */
interface XlsReaderInterface extends IterableReaderInterface, FormatAwareReaderInterface, SheetNamesReaderInterface
{
}
