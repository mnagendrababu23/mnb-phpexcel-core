<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Validation;

final class ArrayValidator
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, string> $rules
     * @param array<string,mixed> $options
     * @return array{valid:list<array<string,mixed>>,failed:list<array{row:int,errors:list<string>,error_details?:list<array<string,mixed>>,data:array<string,mixed>}>}
     */
    public function validate(array $rows, array $rules, array $options = []): array
    {
        $valid = [];
        $failed = [];
        $startRow = (int) ($options['start_row'] ?? 1);
        $rowNumberKey = isset($options['row_number_key']) ? (string) $options['row_number_key'] : null;
        $strictColumns = (bool) ($options['strict_columns'] ?? false);
        $allowedColumns = $this->stringList($options['allowed_columns'] ?? array_keys($rules));
        $duplicateBy = $this->stringList($options['duplicate_by'] ?? []);
        $duplicateRows = $this->duplicateRowNumbers($rows, $duplicateBy, $rowNumberKey, $startRow);
        $uniqueColumns = $this->uniqueRuleColumns($rules);
        $uniqueValues = $this->duplicateValuesByColumn($rows, $uniqueColumns, $rowNumberKey, $startRow);

        foreach ($rows as $index => $row) {
            $rowNumber = $this->rowNumber($row, $index, $rowNumberKey, $startRow);
            $errors = [];
            $details = [];

            if ($strictColumns) {
                $extraColumns = array_values(array_diff(array_keys($row), $allowedColumns));
                if ($extraColumns !== []) {
                    $message = 'Unexpected columns: ' . implode(', ', $extraColumns) . '.';
                    $errors[] = $message;
                    $details[] = $this->detail($rowNumber, '*', $extraColumns, 'strict_columns', $message);
                }

                $missingColumns = array_values(array_diff($allowedColumns, array_keys($row)));
                if ($missingColumns !== []) {
                    $message = 'Missing columns: ' . implode(', ', $missingColumns) . '.';
                    $errors[] = $message;
                    $details[] = $this->detail($rowNumber, '*', $missingColumns, 'strict_columns', $message);
                }
            }

            if (isset($duplicateRows[$rowNumber])) {
                $message = 'Duplicate row for columns: ' . implode(', ', $duplicateBy) . '.';
                $errors[] = $message;
                $details[] = $this->detail($rowNumber, implode(',', $duplicateBy), null, 'duplicate_by', $message);
            }

            foreach ($rules as $column => $ruleString) {
                $value = $row[$column] ?? null;
                $rulesForColumn = array_values(array_filter(array_map('trim', explode('|', $ruleString)), static fn (string $rule): bool => $rule !== ''));
                $isNullableEmpty = in_array('nullable', $rulesForColumn, true) && $this->isEmpty($value);

                foreach ($rulesForColumn as $rule) {
                    if ($rule === 'nullable') {
                        continue;
                    }
                    if ($isNullableEmpty && !str_starts_with($rule, 'required')) {
                        continue;
                    }

                    $error = $this->checkRule($column, $value, $rule, $row, $rows, $uniqueValues, $rowNumber);
                    if ($error !== null) {
                        $errors[] = $error;
                        $details[] = $this->detail($rowNumber, $column, $value, $this->ruleName($rule), $error);
                    }
                }
            }

            if ($errors === []) {
                $valid[] = $row;
            } else {
                $failed[] = [
                    'row' => $rowNumber,
                    'errors' => array_values(array_unique($errors)),
                    'error_details' => $details,
                    'data' => $row,
                ];
            }
        }

        return ['valid' => $valid, 'failed' => $failed];
    }

    /** @param array<string,mixed> $row @param list<array<string,mixed>> $rows @param array<string,array<string,list<int>>> $uniqueValues */
    private function checkRule(string $column, mixed $value, string $rule, array $row = [], array $rows = [], array $uniqueValues = [], ?int $rowNumber = null): ?string
    {
        $rule = trim($rule);
        if ($rule === '') {
            return null;
        }

        $isEmpty = $this->isEmpty($value);

        if ($rule === 'required') {
            return $isEmpty ? $column . ' is required.' : null;
        }

        if (str_starts_with($rule, 'required_if:')) {
            [$otherColumn, $expected] = $this->twoPartRuleArgument($rule, 'required_if:');
            $actual = isset($row[$otherColumn]) ? (string) $row[$otherColumn] : '';
            return $actual === $expected && $isEmpty ? $column . ' is required when ' . $otherColumn . ' is ' . $expected . '.' : null;
        }

        if ($isEmpty) {
            return null;
        }

        if ($rule === 'email' && !filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
            return $column . ' must be a valid email.';
        }

        if ($rule === 'url' && !filter_var((string) $value, FILTER_VALIDATE_URL)) {
            return $column . ' must be a valid URL.';
        }

        if ($rule === 'numeric' && !is_numeric($value)) {
            return $column . ' must be numeric.';
        }

        if ($rule === 'integer' && filter_var($value, FILTER_VALIDATE_INT) === false) {
            return $column . ' must be an integer.';
        }

        if ($rule === 'string' && (is_array($value) || is_object($value))) {
            return $column . ' must be a string.';
        }

        if ($rule === 'boolean' && !in_array($value, [true, false, 0, 1, '0', '1'], true)) {
            return $column . ' must be boolean.';
        }

        if ($rule === 'date' && strtotime((string) $value) === false) {
            return $column . ' must be a valid date.';
        }

        if ($rule === 'alpha' && preg_match('/^[\p{L}\s]+$/u', (string) $value) !== 1) {
            return $column . ' must contain only letters and spaces.';
        }

        if ($rule === 'alpha_num' && preg_match('/^[\p{L}\p{N}_\-\s]+$/u', (string) $value) !== 1) {
            return $column . ' must contain only letters, numbers, spaces, underscores, or hyphens.';
        }

        if ($rule === 'phone_basic' && preg_match('/^\+?[0-9][0-9\s().-]{6,24}$/', (string) $value) !== 1) {
            return $column . ' must be a valid phone number.';
        }

        if ($rule === 'unique_in_file') {
            $key = strtolower(trim((string) $value));
            $duplicates = $uniqueValues[$column][$key] ?? [];
            if (count($duplicates) > 1 && $rowNumber !== null && in_array($rowNumber, $duplicates, true) && $rowNumber !== $duplicates[0]) {
                return $column . ' must be unique in file.';
            }
        }

        if (str_starts_with($rule, 'starts_with:')) {
            $prefixes = $this->csvArgs(substr($rule, 12));
            foreach ($prefixes as $prefix) {
                if (str_starts_with((string) $value, $prefix)) {
                    return null;
                }
            }
            return $column . ' must start with: ' . implode(', ', $prefixes) . '.';
        }

        if (str_starts_with($rule, 'ends_with:')) {
            $suffixes = $this->csvArgs(substr($rule, 10));
            foreach ($suffixes as $suffix) {
                if (str_ends_with((string) $value, $suffix)) {
                    return null;
                }
            }
            return $column . ' must end with: ' . implode(', ', $suffixes) . '.';
        }

        if (str_starts_with($rule, 'max:')) {
            $max = (float) substr($rule, 4);
            if (is_numeric($value) && (float) $value > $max) {
                return $column . ' must be <= ' . $this->displayNumber($max) . '.';
            }
            if (!is_numeric($value) && $this->textLength((string) $value) > (int) $max) {
                return $column . ' length must be <= ' . (int) $max . '.';
            }
        }

        if (str_starts_with($rule, 'min:')) {
            $min = (float) substr($rule, 4);
            if (is_numeric($value) && (float) $value < $min) {
                return $column . ' must be >= ' . $this->displayNumber($min) . '.';
            }
            if (!is_numeric($value) && $this->textLength((string) $value) < (int) $min) {
                return $column . ' length must be >= ' . (int) $min . '.';
            }
        }

        if (str_starts_with($rule, 'length:')) {
            $length = (int) substr($rule, 7);
            if ($this->textLength((string) $value) !== $length) {
                return $column . ' length must be ' . $length . '.';
            }
        }

        if (str_starts_with($rule, 'in:')) {
            $allowed = $this->csvArgs(substr($rule, 3));
            if (!in_array((string) $value, $allowed, true)) {
                return $column . ' must be one of: ' . implode(', ', $allowed) . '.';
            }
        }

        if (str_starts_with($rule, 'regex:')) {
            $pattern = substr($rule, 6);
            if (@preg_match($pattern, '') === false) {
                return $column . ' has an invalid regex validation rule.';
            }
            if (!preg_match($pattern, (string) $value)) {
                return $column . ' format is invalid.';
            }
        }

        $customError = CustomValidatorRegistry::check($column, $value, $rule, $row, $rows, $rowNumber);
        if ($customError !== null) {
            return $customError;
        }

        return null;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $columns
     * @return array<int,true>
     */
    private function duplicateRowNumbers(array $rows, array $columns, ?string $rowNumberKey, int $startRow): array
    {
        if ($columns === []) {
            return [];
        }

        $seen = [];
        $duplicateRows = [];
        foreach ($rows as $index => $row) {
            $parts = [];
            foreach ($columns as $column) {
                $parts[] = strtolower(trim((string) ($row[$column] ?? '')));
            }
            $key = implode('|', $parts);
            if (trim(str_replace('|', '', $key)) === '') {
                continue;
            }

            $rowNumber = $this->rowNumber($row, $index, $rowNumberKey, $startRow);
            if (isset($seen[$key])) {
                $duplicateRows[$rowNumber] = true;
            } else {
                $seen[$key] = $rowNumber;
            }
        }

        return $duplicateRows;
    }

    /** @param list<array<string,mixed>> $rows @param list<string> $columns @return array<string,array<string,list<int>>> */
    private function duplicateValuesByColumn(array $rows, array $columns, ?string $rowNumberKey, int $startRow): array
    {
        $values = [];
        foreach ($columns as $column) {
            $values[$column] = [];
        }
        if ($columns === []) {
            return $values;
        }

        foreach ($rows as $index => $row) {
            $rowNumber = $this->rowNumber($row, $index, $rowNumberKey, $startRow);
            foreach ($columns as $column) {
                $raw = $row[$column] ?? null;
                if ($this->isEmpty($raw)) {
                    continue;
                }
                $key = strtolower(trim((string) $raw));
                $values[$column][$key][] = $rowNumber;
            }
        }

        return $values;
    }

    /** @param array<string,string> $rules @return list<string> */
    private function uniqueRuleColumns(array $rules): array
    {
        $columns = [];
        foreach ($rules as $column => $ruleString) {
            if (in_array('unique_in_file', array_map('trim', explode('|', $ruleString)), true)) {
                $columns[] = (string) $column;
            }
        }
        return $columns;
    }

    /** @param array<string,mixed> $row */
    private function rowNumber(array $row, int $index, ?string $rowNumberKey, int $startRow): int
    {
        if ($rowNumberKey !== null && isset($row[$rowNumberKey]) && is_numeric($row[$rowNumberKey])) {
            return (int) $row[$rowNumberKey];
        }

        return $startRow + $index;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            return [$value];
        }
        if (!is_array($value)) {
            return [(string) $value];
        }
        return array_values(array_map('strval', $value));
    }

    private function displayNumber(float $number): string
    {
        $value = rtrim(rtrim(number_format($number, 6, '.', ''), '0'), '.');
        return $value === '' ? '0' : $value;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '') || (!is_string($value) && trim((string) $value) === '');
    }

    /** @return list<string> */
    private function csvArgs(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));
    }

    /** @return array{0:string,1:string} */
    private function twoPartRuleArgument(string $rule, string $prefix): array
    {
        $args = explode(',', substr($rule, strlen($prefix)), 2);
        return [trim($args[0] ?? ''), trim($args[1] ?? '')];
    }

    private function ruleName(string $rule): string
    {
        $pos = strpos($rule, ':');
        return $pos === false ? $rule : substr($rule, 0, $pos);
    }

    /** @return array<string,mixed> */
    private function detail(int $row, string $column, mixed $value, string $rule, string $message): array
    {
        return [
            'row' => $row,
            'column' => $column,
            'value' => $value,
            'rule' => $rule,
            'message' => $message,
        ];
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
