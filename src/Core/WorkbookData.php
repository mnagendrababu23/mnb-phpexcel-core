<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Core;

final class WorkbookData
{
    /**
     * @param list<WorksheetData> $sheets
     * @param array<string,mixed> $metadata
     */
    public function __construct(public array $sheets, public array $metadata = [])
    {
    }
}
