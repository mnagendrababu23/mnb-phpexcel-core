<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Support;

final class ErrorCode
{
    public const RUNTIME_ERROR = 'MNB_RUNTIME_ERROR';
    public const INVALID_ARGUMENT = 'MNB_INVALID_ARGUMENT';
    public const FILE_NOT_FOUND = 'MNB_FILE_NOT_FOUND';
    public const DIRECTORY_CREATE_FAILED = 'MNB_DIRECTORY_CREATE_FAILED';
    public const FILE_OPEN_FAILED = 'MNB_FILE_OPEN_FAILED';
    public const FILE_READ_FAILED = 'MNB_FILE_READ_FAILED';
    public const FILE_WRITE_FAILED = 'MNB_FILE_WRITE_FAILED';
    public const FILE_REPLACE_FAILED = 'MNB_FILE_REPLACE_FAILED';
    public const UNSUPPORTED_FORMAT = 'MNB_UNSUPPORTED_FORMAT';
    public const EXTENSION_MISSING = 'MNB_EXTENSION_MISSING';
    public const JSON_INVALID = 'MNB_JSON_INVALID';
    public const JSON_ENCODE_FAILED = 'MNB_JSON_ENCODE_FAILED';
    public const JSON_WRITE_FAILED = 'MNB_JSON_WRITE_FAILED';
    public const XML_WRITE_FAILED = 'MNB_XML_WRITE_FAILED';
    public const CSV_WRITE_FAILED = 'MNB_CSV_WRITE_FAILED';
    public const XLSX_WRITE_FAILED = 'MNB_XLSX_WRITE_FAILED';
    public const XLSX_ZIP_ENTRY_FAILED = 'MNB_XLSX_ZIP_ENTRY_FAILED';
    public const XLSX_ZIP_CLOSE_FAILED = 'MNB_XLSX_ZIP_CLOSE_FAILED';
    public const XLSX_INTEGRITY_FAILED = 'MNB_XLSX_INTEGRITY_FAILED';
    public const XLSX_INVALID = 'MNB_XLSX_INVALID';
    public const SQL_IMPORT_FAILED = 'MNB_SQL_IMPORT_FAILED';
    public const SQL_EXPORT_FAILED = 'MNB_SQL_EXPORT_FAILED';
    public const DB_CONFIG_INVALID = 'MNB_DB_CONFIG_INVALID';
    public const DB_CONNECTION_FAILED = 'MNB_DB_CONNECTION_FAILED';
    public const VALIDATION_FAILED = 'MNB_VALIDATION_FAILED';
    public const SECURITY_BLOCKED = 'MNB_SECURITY_BLOCKED';

    private function __construct()
    {
    }

    public static function categoryFor(string $code): string
    {
        return match (true) {
            str_contains($code, 'XLSX') => 'xlsx',
            str_contains($code, 'CSV') => 'csv',
            str_contains($code, 'JSON') => 'json',
            str_contains($code, 'XML') => 'xml',
            str_contains($code, 'SQL') || str_contains($code, 'DB_') => 'sql',
            str_contains($code, 'FILE') || str_contains($code, 'DIRECTORY') => 'filesystem',
            str_contains($code, 'VALIDATION') => 'validation',
            str_contains($code, 'SECURITY') => 'security',
            str_contains($code, 'UNSUPPORTED') || str_contains($code, 'INVALID') => 'input',
            str_contains($code, 'EXTENSION') => 'environment',
            default => 'runtime',
        };
    }

    public static function safeMessageFor(string $code): string
    {
        return match ($code) {
            self::FILE_NOT_FOUND => 'The requested file could not be found.',
            self::DIRECTORY_CREATE_FAILED => 'The export directory could not be created.',
            self::FILE_OPEN_FAILED => 'The file could not be opened.',
            self::FILE_READ_FAILED => 'The file could not be read.',
            self::FILE_WRITE_FAILED,
            self::FILE_REPLACE_FAILED => 'The export file could not be written safely.',
            self::UNSUPPORTED_FORMAT => 'The requested file format is not supported.',
            self::EXTENSION_MISSING => 'A required PHP extension is not enabled.',
            self::JSON_INVALID => 'The JSON file is invalid.',
            self::JSON_ENCODE_FAILED => 'The JSON response could not be generated.',
            self::JSON_WRITE_FAILED => 'The JSON file could not be generated safely.',
            self::XML_WRITE_FAILED => 'The XML file could not be generated safely.',
            self::CSV_WRITE_FAILED => 'The CSV file could not be generated safely.',
            self::XLSX_WRITE_FAILED,
            self::XLSX_ZIP_ENTRY_FAILED,
            self::XLSX_ZIP_CLOSE_FAILED => 'The Excel file could not be generated safely.',
            self::XLSX_INTEGRITY_FAILED,
            self::XLSX_INVALID => 'The Excel file failed integrity validation.',
            self::SQL_IMPORT_FAILED => 'The SQL import failed.',
            self::SQL_EXPORT_FAILED => 'The SQL export failed.',
            self::DB_CONFIG_INVALID => 'The database configuration is invalid or incomplete.',
            self::DB_CONNECTION_FAILED => 'The database connection could not be created.',
            self::VALIDATION_FAILED => 'The supplied data failed validation.',
            self::SECURITY_BLOCKED => 'The operation was blocked by a safety rule.',
            default => 'The spreadsheet operation failed.',
        };
    }
}
