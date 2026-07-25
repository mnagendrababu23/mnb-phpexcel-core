<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Application;

use Throwable;

/** Optional logger bridge. Accepts PSR-3-like objects or callables. */
final class LoggerBridge
{
    private static mixed $logger = null;

    public static function set(mixed $logger): void
    {
        self::$logger = $logger;
    }

    public static function get(): mixed
    {
        return self::$logger;
    }

    /** @param array<string,mixed> $context */
    public static function log(string $level, string $message, array $context = []): void
    {
        $logger = self::$logger;
        if ($logger === null) {
            return;
        }
        try {
            if (is_callable($logger)) {
                $logger($level, $message, $context);
                return;
            }
            if (is_object($logger) && method_exists($logger, $level)) {
                $logger->{$level}($message, $context);
                return;
            }
            if (is_object($logger) && method_exists($logger, 'log')) {
                $logger->log($level, $message, $context);
            }
        } catch (Throwable) {
            // Logger failures must not break imports/exports.
        }
    }

    /** @param array<string,mixed> $context */
    public static function info(string $message, array $context = []): void { self::log('info', $message, $context); }
    /** @param array<string,mixed> $context */
    public static function warning(string $message, array $context = []): void { self::log('warning', $message, $context); }
    /** @param array<string,mixed> $context */
    public static function error(string $message, array $context = []): void { self::log('error', $message, $context); }
}
