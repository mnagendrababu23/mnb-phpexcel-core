<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Support;

use Mnb\PHPExcel\Support\Zip\ZipArchive;

final class FileFormatDetector
{
    /** @var list<string> */
    private const SUPPORTED = ['xlsx', 'csv', 'json', 'xml', 'ods', 'xls'];

    /**
     * Detect a supported reader format from an explicit option, extension, and file signature.
     *
     * @param array<string,mixed> $options
     */
    public static function detect(string $path, array $options = []): string
    {
        if (!is_file($path)) {
            throw MnbExcelException::withCode(
                'File not found: ' . $path,
                ErrorCode::FILE_NOT_FOUND,
                ['path' => $path]
            );
        }

        $explicit = strtolower(trim((string) ($options['format'] ?? $options['reader'] ?? 'auto')));
        if ($explicit !== '' && $explicit !== 'auto') {
            $explicit = self::normalize($explicit);
            if (!in_array($explicit, self::SUPPORTED, true)) {
                throw MnbExcelException::withCode(
                    'Unsupported reader format: ' . $explicit,
                    ErrorCode::UNSUPPORTED_FORMAT,
                    ['format' => $explicit, 'path' => $path]
                );
            }
            return $explicit;
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $byExtension = match ($extension) {
            'xlsx', 'xlsm', 'xltx', 'xltm' => 'xlsx',
            'csv', 'tsv', 'txt' => 'csv',
            'json', 'jsonl', 'ndjson' => 'json',
            'xml' => 'xml',
            'ods', 'ots' => 'ods',
            'xls', 'xlt' => 'xls',
            default => null,
        };

        $sample = self::sample($path, (int) ($options['format_sample_bytes'] ?? 4096));
        $trimmed = ltrim(self::stripBom($sample));

        if (str_starts_with($sample, "PK\x03\x04") || str_starts_with($sample, "PK\x05\x06") || str_starts_with($sample, "PK\x07\x08")) {
            $zipFormat = self::zipSpreadsheetFormat($path);
            if ($zipFormat !== null) {
                return $zipFormat;
            }
            if ($byExtension === 'xlsx' || $byExtension === 'ods') {
                return $byExtension;
            }
        }

        if (str_starts_with($sample, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            return 'xls';
        }

        if ($trimmed !== '') {
            $first = $trimmed[0];
            if ($first === '{' || $first === '[' || self::looksLikeNdjsonSample($trimmed)) {
                return 'json';
            }
            if ($first === '<') {
                return 'xml';
            }
        }

        if ($byExtension !== null) {
            return $byExtension;
        }

        if (str_contains($sample, ',') || str_contains($sample, ';') || str_contains($sample, "\t") || str_contains($sample, '|')) {
            return 'csv';
        }

        throw MnbExcelException::withCode(
            'Unable to detect a supported reader format for: ' . $path,
            ErrorCode::UNSUPPORTED_FORMAT,
            ['path' => $path, 'extension' => $extension]
        );
    }

    private static function normalize(string $format): string
    {
        return match ($format) {
            'xlsm', 'xltx', 'xltm', 'excel' => 'xlsx',
            'tsv', 'text' => 'csv',
            'jsonl', 'ndjson' => 'json',
            'ots' => 'ods',
            'xlt' => 'xls',
            default => $format,
        };
    }

    private static function sample(string $path, int $bytes): string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw MnbExcelException::withCode(
                'Unable to open file for format detection: ' . $path,
                ErrorCode::FILE_OPEN_FAILED,
                ['path' => $path]
            );
        }

        try {
            $sample = fread($handle, max(64, $bytes));
            if ($sample === false) {
                throw MnbExcelException::withCode(
                    'Unable to read file signature: ' . $path,
                    ErrorCode::FILE_READ_FAILED,
                    ['path' => $path]
                );
            }
            return $sample;
        } finally {
            fclose($handle);
        }
    }

    private static function looksLikeNdjsonSample(string $sample): bool
    {
        $lines = preg_split('/\R/u', trim($sample)) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== ''));
        if (count($lines) < 2) {
            return false;
        }

        foreach (array_slice($lines, 0, 20) as $line) {
            try {
                json_decode($line, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (\JsonException) {
                return false;
            }
        }

        return true;
    }

    private static function zipSpreadsheetFormat(string $path): ?string
    {
        if (!class_exists(ZipArchive::class)) {
            return null;
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        try {
            if ($zip->locateName('[Content_Types].xml') !== false && $zip->locateName('xl/workbook.xml') !== false) {
                return 'xlsx';
            }
            if ($zip->locateName('content.xml') !== false) {
                $mimetype = $zip->getFromName('mimetype');
                if (!is_string($mimetype) || trim($mimetype) === '' || trim($mimetype) === 'application/vnd.oasis.opendocument.spreadsheet') {
                    return 'ods';
                }
            }
            return null;
        } finally {
            $zip->close();
        }
    }

    private static function stripBom(string $value): string
    {
        foreach (["\xEF\xBB\xBF", "\xFF\xFE\x00\x00", "\x00\x00\xFE\xFF", "\xFF\xFE", "\xFE\xFF"] as $bom) {
            if (str_starts_with($value, $bom)) {
                return substr($value, strlen($bom));
            }
        }
        return $value;
    }

    private function __construct()
    {
    }
}
