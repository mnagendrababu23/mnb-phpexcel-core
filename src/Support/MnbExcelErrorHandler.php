<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Support;

use Throwable;

/**
 * Optional global exception renderer for applications that want clean MNB PHPExcel
 * errors without PHP's uncaught-exception stack trace.
 *
 * Libraries should not register global handlers automatically. Applications opt in
 * once near their bootstrap code by calling MnbExcelErrorHandler::register().
 */
final class MnbExcelErrorHandler
{
    /** @var callable(Throwable):void|null */
    private static $previousHandler = null;

    /** @var array<string,mixed> */
    private static array $options = [];

    private static bool $registered = false;

    private function __construct()
    {
    }

    /**
     * Register a clean global exception handler.
     *
     * Supported options:
     * - debug: include the actionable developer message and safe context (default false)
     * - format: auto, text, html, or json (default auto)
     * - handle_all: render non-MNB exceptions too (default false)
     * - renderer: callable(array $error, Throwable $throwable): string|null
     * - http_status: set an appropriate HTTP status when not running in CLI (default true)
     * - exit_code: process exit code after rendering (default 1)
     *
     * @param array<string,mixed> $options
     */
    public static function register(array $options = []): void
    {
        self::$options = array_replace([
            'debug' => false,
            'format' => 'auto',
            'handle_all' => false,
            'renderer' => null,
            'http_status' => true,
            'exit_code' => 1,
        ], $options);

        self::validateOptions(self::$options);

        if (self::$registered) {
            return;
        }

        self::$previousHandler = set_exception_handler([self::class, 'handle']);
        self::$registered = true;
    }

    /** Register the handler with actionable developer messages enabled. */
    public static function registerDeveloperMode(string $format = 'auto', ?callable $renderer = null): void
    {
        self::register([
            'debug' => true,
            'format' => $format,
            'renderer' => $renderer,
        ]);
    }

    /** Restore the exception handler that was active before registration. */
    public static function unregister(): void
    {
        if (!self::$registered) {
            return;
        }

        restore_exception_handler();
        self::$registered = false;
        self::$previousHandler = null;
        self::$options = [];
    }

    public static function isRegistered(): bool
    {
        return self::$registered;
    }

    /** Render an exception without registering or terminating the process. */
    public static function render(Throwable $throwable, bool $debug = false, string $format = 'auto'): string
    {
        $resolvedFormat = self::resolveFormat($format);
        $error = ErrorReporter::report($throwable, $debug);

        return match ($resolvedFormat) {
            'json' => self::renderJson($error),
            'html' => self::renderHtml($error),
            default => self::renderText($error),
        };
    }

    /** @internal Registered through set_exception_handler(). */
    public static function handle(Throwable $throwable): void
    {
        $options = self::$options !== [] ? self::$options : [
            'debug' => false,
            'format' => 'auto',
            'handle_all' => false,
            'renderer' => null,
            'http_status' => true,
            'exit_code' => 1,
        ];

        if (!$throwable instanceof MnbExcelException && !(bool) $options['handle_all']) {
            if (is_callable(self::$previousHandler)) {
                (self::$previousHandler)($throwable);
                return;
            }

            // There is no previous handler to delegate to. Render a safe generic error
            // rather than triggering a second fatal error inside the exception handler.
            $options['debug'] = false;
        }

        $debug = (bool) $options['debug'];
        $format = self::resolveFormat((string) $options['format']);
        $error = ErrorReporter::report($throwable, $debug);

        if ((bool) $options['http_status'] && !self::isCli() && !headers_sent()) {
            http_response_code(self::httpStatusFor($error));
            header('Content-Type: ' . self::contentTypeFor($format));
        }

        $renderer = $options['renderer'];
        if (is_callable($renderer)) {
            $result = $renderer($error, $throwable);
            if (is_string($result) && $result !== '') {
                echo $result;
            }
        } else {
            echo match ($format) {
                'json' => self::renderJson($error),
                'html' => self::renderHtml($error),
                default => self::renderText($error),
            };
        }

        exit(max(0, (int) $options['exit_code']));
    }

    /** @param array<string,mixed> $options */
    private static function validateOptions(array $options): void
    {
        $format = strtolower(trim((string) ($options['format'] ?? 'auto')));
        if (!in_array($format, ['auto', 'text', 'html', 'json'], true)) {
            throw MnbExcelException::withCode(
                'Invalid MNB error-handler format "' . $format . '". Use auto, text, html, or json.',
                ErrorCode::INVALID_ARGUMENT,
                ['format' => $format]
            );
        }

        $renderer = $options['renderer'] ?? null;
        if ($renderer !== null && !is_callable($renderer)) {
            throw MnbExcelException::withCode(
                'The MNB error-handler renderer must be callable or null.',
                ErrorCode::INVALID_ARGUMENT
            );
        }
    }

    private static function resolveFormat(string $format): string
    {
        $format = strtolower(trim($format));
        if ($format !== '' && $format !== 'auto') {
            return in_array($format, ['text', 'html', 'json'], true) ? $format : 'text';
        }

        if (self::isCli()) {
            return 'text';
        }

        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        if (str_contains($accept, 'application/json')) {
            return 'json';
        }

        return 'html';
    }

    private static function isCli(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }

    /** @param array<string,mixed> $error */
    private static function renderText(array $error): string
    {
        $message = (string) ($error['developer_message'] ?? $error['message'] ?? 'The spreadsheet operation failed.');
        $code = (string) ($error['code'] ?? ErrorCode::RUNTIME_ERROR);

        $lines = [
            'MNB PHPExcel Error [' . $code . ']',
            $message,
        ];

        if (isset($error['context']) && is_array($error['context'])) {
            $callerFile = $error['context']['caller_file'] ?? null;
            $callerLine = $error['context']['caller_line'] ?? null;
            if (is_string($callerFile) && $callerFile !== '' && is_int($callerLine)) {
                $location = $callerFile . ':' . $callerLine;
                if (!str_contains($message, $location)) {
                    $lines[] = 'Application location: ' . $location;
                }
            }
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /** @param array<string,mixed> $error */
    private static function renderHtml(array $error): string
    {
        $code = self::escape((string) ($error['code'] ?? ErrorCode::RUNTIME_ERROR));
        $message = self::escape((string) ($error['developer_message'] ?? $error['message'] ?? 'The spreadsheet operation failed.'));

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>MNB PHPExcel Error</title></head><body>'
            . '<main style="font-family:system-ui,sans-serif;max-width:900px;margin:3rem auto;padding:0 1rem">'
            . '<h1 style="font-size:1.4rem">MNB PHPExcel Error</h1>'
            . '<p><strong>Code:</strong> <code>' . $code . '</code></p>'
            . '<p style="white-space:pre-wrap">' . $message . '</p>'
            . '</main></body></html>';
    }

    /** @param array<string,mixed> $error */
    private static function renderJson(array $error): string
    {
        $json = json_encode($error, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return '{"status":"error","code":"MNB_RUNTIME_ERROR","message":"The spreadsheet operation failed."}';
        }

        return $json . PHP_EOL;
    }

    /** @param array<string,mixed> $error */
    private static function httpStatusFor(array $error): int
    {
        $code = (string) ($error['code'] ?? ErrorCode::RUNTIME_ERROR);
        return match ($code) {
            ErrorCode::FILE_NOT_FOUND => 404,
            ErrorCode::SHEET_SELECTION_REQUIRED,
            ErrorCode::SHEET_INDEX_INVALID,
            ErrorCode::SHEET_NAME_INVALID,
            ErrorCode::SHEET_NOT_FOUND,
            ErrorCode::SHEET_NAME_AMBIGUOUS,
            ErrorCode::SHEET_EMPTY,
            ErrorCode::INVALID_ARGUMENT,
            ErrorCode::VALIDATION_FAILED => 422,
            ErrorCode::UNSUPPORTED_FORMAT => 415,
            ErrorCode::EXTENSION_MISSING => 500,
            ErrorCode::SECURITY_BLOCKED => 403,
            default => 500,
        };
    }

    private static function contentTypeFor(string $format): string
    {
        return match ($format) {
            'json' => 'application/json; charset=UTF-8',
            'html' => 'text/html; charset=UTF-8',
            default => 'text/plain; charset=UTF-8',
        };
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
