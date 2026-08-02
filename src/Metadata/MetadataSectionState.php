<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Metadata;

use Mnb\PHPExcel\Support\MnbExcelException;

final class MetadataSectionState
{
    public const AVAILABLE = 'available';
    public const PARTIAL = 'partial';
    public const NOT_SUPPORTED = 'not_supported';
    public const NOT_APPLICABLE = 'not_applicable';
    public const NOT_SCANNED = 'not_scanned';
    public const ENCRYPTED = 'encrypted';
    public const PASSWORD_REQUIRED = 'password_required';
    public const ERROR = 'error';

    /** @return list<string> */
    public static function values(): array
    {
        return [
            self::AVAILABLE,
            self::PARTIAL,
            self::NOT_SUPPORTED,
            self::NOT_APPLICABLE,
            self::NOT_SCANNED,
            self::ENCRYPTED,
            self::PASSWORD_REQUIRED,
            self::ERROR,
        ];
    }

    public static function assert(string $state): string
    {
        if (!in_array($state, self::values(), true)) {
            throw new MnbExcelException('Unknown metadata section state: ' . $state);
        }

        return $state;
    }
}
