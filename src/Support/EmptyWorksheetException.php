<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Support;

/** Raised when a caller explicitly requires at least one normalized data row. */
final class EmptyWorksheetException extends MnbExcelException
{
    /**
     * @param int|string $sheet
     * @param array<string,mixed> $options
     */
    public static function forSheet(string $path, int|string $sheet, array $options = [], ?string $customMessage = null): self
    {
        $context = [
            'path' => $path,
            'selected_sheet' => $sheet,
            'options' => self::safeOptions($options),
        ];

        $caller = self::findCaller();
        if ($caller !== null) {
            $context += $caller;
        }

        $message = $customMessage !== null && trim($customMessage) !== ''
            ? trim($customMessage)
            : 'The selected worksheet contains zero readable data rows after applying the current header, range, skip, filter, and empty-row options.';

        $message .= ' Workbook: "' . $path . '". Selected worksheet: '
            . (is_int($sheet) ? '#' . $sheet : '"' . $sheet . '"') . '.';
        $message .= ' Use ->hasRows(), ->isEmpty(), or ->countRows() before processing optional worksheets.';

        if (isset($context['caller_file'], $context['caller_line'])) {
            $message .= ' Called from ' . $context['caller_file'] . ':' . $context['caller_line'] . '.';
        }

        return new self(
            $message,
            ErrorCode::SHEET_EMPTY,
            'input',
            $context,
            null,
            'The selected worksheet does not contain readable data rows.'
        );
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private static function safeOptions(array $options): array
    {
        $safe = $options;
        foreach (['password', 'encryption_password', 'database_password'] as $secret) {
            if (array_key_exists($secret, $safe)) {
                $safe[$secret] = '[redacted]';
            }
        }
        return $safe;
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

            $normalized = str_replace('\\', '/', $file);
            if (str_contains(strtolower($normalized), '/vendor/mnb/')
                || preg_match('~/mnb-phpexcel(?:-[^/]+)?/src/~i', $normalized) === 1) {
                continue;
            }

            return ['caller_file' => $file, 'caller_line' => $line];
        }

        return null;
    }
}
