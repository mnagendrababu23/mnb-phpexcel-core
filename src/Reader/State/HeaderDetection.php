<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Reader\State;

final class HeaderDetection implements \JsonSerializable
{
    /** @param list<array{row:int,score:float,reasons:list<string>}> $candidates */
    public function __construct(
        public readonly int $row,
        public readonly float $confidence,
        public readonly array $candidates = []
    ) {
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'row' => $this->row,
            'confidence' => $this->confidence,
            'candidates' => $this->candidates,
        ];
    }
}
