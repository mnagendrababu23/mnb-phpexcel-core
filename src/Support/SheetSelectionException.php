<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Support;

/**
 * Developer-facing worksheet selection error with actionable context.
 */
final class SheetSelectionException extends MnbExcelException
{
    /** @param list<string> $availableSheets */
    public static function missing(string $path, array $availableSheets = []): self
    {
        $context = self::buildContext($path, null, $availableSheets);
        $message = 'No worksheet was passed to sheet(). '
            . 'Pass a 1-based worksheet number such as ->sheet(1), or an existing worksheet name such as ->sheet(\'Data\'). '
            . 'To read the first worksheet, omit ->sheet() entirely because the first worksheet is selected by default.'
            . self::workbookDetails($path, $availableSheets);

        return self::create($message, ErrorCode::SHEET_SELECTION_REQUIRED, $context, 'A worksheet selection was not provided.');
    }

    /** @param list<string> $availableSheets */
    public static function invalidIndex(int $requested, string $path, array $availableSheets = []): self
    {
        $context = self::buildContext($path, $requested, $availableSheets);
        $message = sprintf(
            'Worksheet number %d is invalid. Worksheet numbers are 1-based; use ->sheet(1) for the first worksheet.',
            $requested
        ) . self::workbookDetails($path, $availableSheets);

        return self::create($message, ErrorCode::SHEET_INDEX_INVALID, $context, 'The worksheet number is invalid.');
    }

    /** @param list<string> $availableSheets */
    public static function emptyName(string $path, array $availableSheets = []): self
    {
        $context = self::buildContext($path, '', $availableSheets);
        $message = 'Worksheet name cannot be empty. Pass an existing worksheet name, or use a 1-based number such as ->sheet(1).'
            . self::workbookDetails($path, $availableSheets);

        return self::create($message, ErrorCode::SHEET_NAME_INVALID, $context, 'The worksheet name is invalid.');
    }

    /** @param list<string> $availableSheets */
    public static function notFound(int|string $requested, string $path, array $availableSheets = []): self
    {
        $context = self::buildContext($path, $requested, $availableSheets);
        $requestedDescription = is_int($requested) || ctype_digit((string) $requested)
            ? 'Worksheet number ' . (int) $requested
            : 'Worksheet "' . (string) $requested . '"';

        $message = $requestedDescription . ' was not found in workbook "' . $path . '".';
        if ($availableSheets !== []) {
            $message .= ' Available worksheets: ' . self::formatAvailableSheets($availableSheets) . '.';
        } else {
            $message .= ' The workbook does not expose any readable worksheets.';
        }
        $message .= ' Call ->sheetNames() to inspect worksheet names before selecting one.';

        return self::create($message, ErrorCode::SHEET_NOT_FOUND, $context, 'The requested worksheet could not be found.');
    }

    /** @param list<string> $availableSheets */
    public static function ambiguousName(string $requested, string $path, array $availableSheets = []): self
    {
        $context = self::buildContext($path, $requested, $availableSheets);
        $message = 'Worksheet name "' . $requested . '" is ambiguous. Pass the exact worksheet name or its 1-based worksheet number.';

        return self::create($message, ErrorCode::SHEET_NAME_AMBIGUOUS, $context, 'The worksheet name is ambiguous.');
    }

    /** @param array<string,mixed> $context */
    private static function create(string $message, string $code, array $context, string $safeMessage): self
    {
        if (isset($context['caller_file'], $context['caller_line'])) {
            $message .= ' Called from ' . $context['caller_file'] . ':' . $context['caller_line'] . '.';
        }

        return new self($message, $code, 'input', $context, null, $safeMessage);
    }

    /**
     * @param int|string|null $requested
     * @param list<string> $availableSheets
     * @return array<string,mixed>
     */
    private static function buildContext(string $path, int|string|null $requested, array $availableSheets): array
    {
        $context = [
            'path' => $path,
            'requested_sheet' => $requested,
            'available_sheets' => array_values($availableSheets),
            'sheet_index_base' => 1,
        ];

        $caller = self::findCaller();
        if ($caller !== null) {
            $context += $caller;
        }

        return $context;
    }

    /** @return array{caller_file:string,caller_line:int}|null */
    private static function findCaller(): ?array
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20) as $frame) {
            $file = $frame['file'] ?? null;
            $line = $frame['line'] ?? null;
            if (!is_string($file) || $file === '' || !is_int($line)) {
                continue;
            }
            if (self::isLibrarySourceFile($file)) {
                continue;
            }

            return ['caller_file' => $file, 'caller_line' => $line];
        }

        return null;
    }

    private static function isLibrarySourceFile(string $file): bool
    {
        $normalized = str_replace('\\', '/', $file);
        if (str_contains(strtolower($normalized), '/vendor/mnb/')) {
            return true;
        }

        return preg_match('~/mnb-phpexcel(?:-[^/]+)?/src/~i', $normalized) === 1;
    }


    /** @param list<string> $availableSheets */
    private static function workbookDetails(string $path, array $availableSheets): string
    {
        $details = ' Workbook: "' . $path . '".';
        if ($availableSheets !== []) {
            $details .= ' Available worksheets: ' . self::formatAvailableSheets($availableSheets) . '.';
        }

        return $details;
    }

    /** @param list<string> $availableSheets */
    private static function formatAvailableSheets(array $availableSheets): string
    {
        $shown = array_slice($availableSheets, 0, 10);
        $items = [];
        foreach ($shown as $index => $name) {
            $items[] = ($index + 1) . '="' . $name . '"';
        }

        if (count($availableSheets) > count($shown)) {
            $items[] = '… +' . (count($availableSheets) - count($shown)) . ' more';
        }

        return implode(', ', $items);
    }
}
