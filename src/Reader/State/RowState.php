<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Reader\State;

final class RowState implements \JsonSerializable
{
    // sourceRow and outputRow are both one-based for application-facing progress.

    /**
     * @param array<int|string,mixed> $values
     * @param list<array{message:string,type:string}> $errors
     */
    public function __construct(
        public readonly int $sourceRow,
        public readonly int $outputRow,
        public readonly int|string $sheet,
        public readonly array $values,
        public readonly array $errors = [],
        public readonly bool $skipped = false,
    ) {
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'source_row' => $this->sourceRow,
            'output_row' => $this->outputRow,
            'sheet' => $this->sheet,
            'values' => $this->values,
            'errors' => $this->errors,
            'skipped' => $this->skipped,
        ];
    }
}
