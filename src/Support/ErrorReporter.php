<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Support;

use Throwable;

final class ErrorReporter
{
    private function __construct()
    {
    }

    /** @return array<string,mixed> */
    public static function safe(Throwable $throwable): array
    {
        return self::report($throwable, false);
    }

    /** @return array<string,mixed> */
    public static function report(Throwable $throwable, bool $debug = false): array
    {
        if ($throwable instanceof MnbExcelException) {
            $code = $throwable->getErrorCode();
            $category = $throwable->category();
            $message = $debug ? $throwable->getMessage() : $throwable->safeMessage();
            $context = $throwable->context();
        } else {
            $code = ErrorCode::RUNTIME_ERROR;
            $category = 'runtime';
            $message = $debug ? $throwable->getMessage() : ErrorCode::safeMessageFor($code);
            $context = [];
        }

        $out = [
            'status' => 'error',
            'code' => $code,
            'category' => $category,
            'message' => $message,
            'safe_message' => $throwable instanceof MnbExcelException ? $throwable->safeMessage() : ErrorCode::safeMessageFor($code),
            'recoverable' => self::isRecoverable($code),
        ];

        if ($debug) {
            $out['developer_message'] = $throwable->getMessage();
            $out['exception'] = get_class($throwable);
            if ($throwable instanceof MnbExcelException && $context !== []) {
                $out['context'] = self::safeContext($context);
            }
            if ($throwable->getPrevious() !== null) {
                $out['previous'] = [
                    'exception' => get_class($throwable->getPrevious()),
                    'message' => $throwable->getPrevious()->getMessage(),
                ];
            }
        }

        return $out;
    }

    private static function isRecoverable(string $code): bool
    {
        return in_array($code, [
            ErrorCode::FILE_NOT_FOUND,
            ErrorCode::UNSUPPORTED_FORMAT,
            ErrorCode::EXTENSION_MISSING,
            ErrorCode::JSON_INVALID,
            ErrorCode::VALIDATION_FAILED,
            ErrorCode::SECURITY_BLOCKED,
        ], true);
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private static function safeContext(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            } elseif (is_array($value)) {
                $safe[$key] = '[array:' . count($value) . ']';
            } else {
                $safe[$key] = '[' . get_debug_type($value) . ']';
            }
        }
        return $safe;
    }
}
