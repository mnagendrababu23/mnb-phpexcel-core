<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Reader\State;

final class FormulaResult implements \JsonSerializable
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public readonly string $formula,
        public readonly mixed $cachedValue,
        public readonly string $resultType = 'unknown',
        public readonly array $metadata = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'formula' => $this->formula,
            'cached_value' => $this->cachedValue,
            'result_type' => $this->resultType,
            'metadata' => $this->metadata,
        ];
    }
}
