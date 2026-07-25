<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Support;

use Throwable;

final class AtomicFileWriter
{
    private function __construct()
    {
    }

    /** @param callable(string):void $writer @param callable(string):void|null $validator */
    public static function writeViaTemp(string $path, callable $writer, ?callable $validator = null): void
    {
        $dir = self::ensureDirectoryFor($path);
        $tmp = self::tempPath($path, $dir);
        $backup = null;

        try {
            $writer($tmp);

            if ($validator !== null) {
                $validator($tmp);
            }

            self::replace($tmp, $path, $backup);
        } catch (MnbExcelException $e) {
            self::cleanup($tmp, $backup, $path);
            throw $e;
        } catch (Throwable $e) {
            self::cleanup($tmp, $backup, $path);
            throw MnbExcelException::withCode(
                'Atomic file save failed: ' . $e->getMessage(),
                ErrorCode::FILE_WRITE_FAILED,
                ['path' => $path],
                $e
            );
        }
    }

    public static function writeString(string $path, string $contents, string $errorCode = ErrorCode::FILE_WRITE_FAILED): void
    {
        self::writeViaTemp($path, static function (string $tmp) use ($contents, $path, $errorCode): void {
            $bytes = @file_put_contents($tmp, $contents, LOCK_EX);
            if ($bytes === false || $bytes !== strlen($contents)) {
                throw MnbExcelException::withCode(
                    'Unable to write file: ' . $path,
                    $errorCode,
                    ['path' => $path, 'expected_bytes' => strlen($contents), 'written_bytes' => $bytes === false ? null : $bytes]
                );
            }
        });
    }

    public static function ensureDirectoryFor(string $path): string
    {
        $dir = dirname($path);
        if ($dir === '' || $dir === '.') {
            $dir = getcwd() ?: '.';
        }

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw MnbExcelException::withCode(
                'Unable to create directory: ' . $dir,
                ErrorCode::DIRECTORY_CREATE_FAILED,
                ['directory' => $dir]
            );
        }

        if (!is_writable($dir)) {
            throw MnbExcelException::withCode(
                'Directory is not writable: ' . $dir,
                ErrorCode::DIRECTORY_CREATE_FAILED,
                ['directory' => $dir]
            );
        }

        return $dir;
    }

    private static function tempPath(string $path, string $dir): string
    {
        $base = basename($path);
        $candidate = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.' . $base . '.tmp.' . bin2hex(random_bytes(8));
        if (@file_put_contents($candidate, '') === false) {
            throw MnbExcelException::withCode(
                'Unable to create temporary export file near: ' . $path,
                ErrorCode::FILE_WRITE_FAILED,
                ['path' => $path]
            );
        }
        @unlink($candidate);
        return $candidate;
    }

    private static function replace(string $tmp, string $path, ?string &$backup): void
    {
        if (!is_file($tmp)) {
            throw MnbExcelException::withCode(
                'Temporary export file was not created: ' . $tmp,
                ErrorCode::FILE_WRITE_FAILED,
                ['tmp' => $tmp, 'path' => $path]
            );
        }

        if (is_file($path)) {
            $backup = $path . '.mnbbak.' . bin2hex(random_bytes(6));
            if (!@rename($path, $backup)) {
                throw MnbExcelException::withCode(
                    'Unable to prepare existing file for safe replacement: ' . $path,
                    ErrorCode::FILE_REPLACE_FAILED,
                    ['path' => $path]
                );
            }
        }

        if (!@rename($tmp, $path)) {
            if ($backup !== null && is_file($backup)) {
                @rename($backup, $path);
            }
            throw MnbExcelException::withCode(
                'Unable to move temporary export file into final path: ' . $path,
                ErrorCode::FILE_REPLACE_FAILED,
                ['tmp' => $tmp, 'path' => $path]
            );
        }

        if ($backup !== null && is_file($backup)) {
            @unlink($backup);
            $backup = null;
        }
    }

    private static function cleanup(string $tmp, ?string $backup, string $path): void
    {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
        if ($backup !== null && is_file($backup) && !is_file($path)) {
            @rename($backup, $path);
        } elseif ($backup !== null && is_file($backup)) {
            @unlink($backup);
        }
    }
}
