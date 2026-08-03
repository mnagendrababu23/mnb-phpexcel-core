<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Snapshot;

interface VisualSnapshotReaderInterface
{
    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function visualSnapshot(string $path, array $options = []): array;
}
