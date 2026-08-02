<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Metadata;

interface MetadataWriterInterface
{
    /** @param array<string,mixed> $changes @param array<string,mixed> $options */
    public function updateMetaInfo(string $source, string $destination, array $changes, array $options = []): void;

    /** @param array<string,mixed> $options */
    public function removePersonalInfo(string $source, string $destination, array $options = []): void;
}
