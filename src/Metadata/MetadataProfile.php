<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Metadata;

use Mnb\PHPExcel\Support\MnbExcelException;

final class MetadataProfile
{
    public const QUICK = 'quick';
    public const STANDARD = 'standard';
    public const FULL = 'full';
    public const FORENSIC = 'forensic';

    /** @return list<string> */
    public static function values(): array
    {
        return [self::QUICK, self::STANDARD, self::FULL, self::FORENSIC];
    }

    public static function normalize(mixed $profile): string
    {
        $value = strtolower(trim((string) ($profile ?? self::STANDARD)));
        if (!in_array($value, self::values(), true)) {
            throw new MnbExcelException('Metadata profile must be quick, standard, full, or forensic.');
        }

        return $value;
    }

    public static function rank(string $profile): int
    {
        return array_search(self::normalize($profile), self::values(), true);
    }

    public static function atLeast(string $profile, string $minimum): bool
    {
        return self::rank($profile) >= self::rank($minimum);
    }
}
