<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Reader\State;

final class ReadProgress implements \JsonSerializable
{
    public function __construct(
        public readonly string $path,
        public readonly int|string $sheet,
        public readonly int $sourceRows,
        public readonly int $outputRows,
        public readonly int $errorRows,
        public readonly float $elapsedSeconds,
        public readonly bool $completed = false,
    ) {
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'path' => $this->path,
            'sheet' => $this->sheet,
            'source_rows' => $this->sourceRows,
            'output_rows' => $this->outputRows,
            'error_rows' => $this->errorRows,
            'elapsed_seconds' => $this->elapsedSeconds,
            'completed' => $this->completed,
        ];
    }
}
