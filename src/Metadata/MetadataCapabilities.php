<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Metadata;

final class MetadataCapabilities
{
    /**
     * @param array<string,mixed> $report
     * @param list<string> $writableSections
     * @return array<string,array{state:string,read:bool,write:bool}>
     */
    public static function fromReport(array $report, array $writableSections = []): array
    {
        $capabilities = [];
        foreach (MetadataReport::SECTIONS as $section) {
            $state = (string) (($report[$section]['state'] ?? MetadataSectionState::NOT_SCANNED));
            $capabilities[$section] = [
                'state' => $state,
                'read' => !in_array($state, [MetadataSectionState::NOT_SUPPORTED, MetadataSectionState::NOT_APPLICABLE], true),
                'write' => in_array($section, $writableSections, true),
            ];
        }
        return $capabilities;
    }
}
