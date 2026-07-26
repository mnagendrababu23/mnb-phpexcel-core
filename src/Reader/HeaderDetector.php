<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Reader;

use Mnb\PHPExcel\Reader\State\HeaderDetection;

final class HeaderDetector
{
    /**
     * Detect the most likely physical header row in a source sample.
     *
     * @param array<int,array<int,mixed>> $rows keyed by zero-based source row index
     */
    public function detect(array $rows, int $maxRows = 25): HeaderDetection
    {
        $candidates = [];
        $rows = array_slice($rows, 0, max(1, $maxRows), true);
        foreach ($rows as $sourceIndex => $row) {
            $row = array_values($row);
            if (!$this->nonEmpty($row)) {
                continue;
            }

            $score = 0.0;
            $reasons = [];
            $nonEmpty = array_values(array_filter($row, static fn (mixed $value): bool => $value !== null && trim((string) $value) !== ''));
            $count = count($nonEmpty);
            if ($count === 0) {
                continue;
            }

            $strings = 0;
            $identifierLike = 0;
            $unique = [];
            foreach ($nonEmpty as $value) {
                $text = trim((string) $value);
                $unique[$this->lower($text)] = true;
                if (!is_numeric($value) && !$this->looksDate($text)) {
                    $strings++;
                }
                if (preg_match('/^[\p{L}_][\p{L}\p{N}_ .\-\/()]{0,80}$/u', $text) === 1) {
                    $identifierLike++;
                }
            }

            $stringRatio = $strings / $count;
            $identifierRatio = $identifierLike / $count;
            $uniqueRatio = count($unique) / $count;
            $score += $stringRatio * 0.35;
            $score += $identifierRatio * 0.25;
            $score += $uniqueRatio * 0.15;
            if ($count >= 2) {
                $score += 0.10;
                $reasons[] = 'multiple populated columns';
            }
            if ($stringRatio >= 0.75) {
                $reasons[] = 'mostly text labels';
            }
            if ($uniqueRatio === 1.0) {
                $reasons[] = 'unique labels';
            }

            $next = $this->nextNonEmptyRow($rows, (int) $sourceIndex);
            if ($next !== null) {
                $nextNonEmpty = array_values(array_filter($next, static fn (mixed $value): bool => $value !== null && trim((string) $value) !== ''));
                if ($nextNonEmpty !== []) {
                    $nextDataLike = 0;
                    foreach ($nextNonEmpty as $value) {
                        if (is_numeric($value) || $this->looksDate(trim((string) $value))) {
                            $nextDataLike++;
                        }
                    }
                    $dataRatio = $nextDataLike / count($nextNonEmpty);
                    $score += $dataRatio * 0.20;
                    if ($dataRatio >= 0.4) {
                        $reasons[] = 'followed by data-like values';
                    }
                }
            }

            // Prefer earlier plausible rows without making row 1 mandatory.
            $score -= min(0.12, ((int) $sourceIndex) * 0.005);
            $candidates[] = [
                'row' => (int) $sourceIndex + 1,
                'score' => round(max(0.0, min(1.0, $score)), 4),
                'reasons' => $reasons,
            ];
        }

        usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: $a['row'] <=> $b['row']);
        $best = $candidates[0] ?? ['row' => 1, 'score' => 0.0, 'reasons' => ['fallback to first row']];
        $second = $candidates[1]['score'] ?? 0.0;
        $confidence = max(0.0, min(1.0, (float) $best['score'] * 0.75 + max(0.0, (float) $best['score'] - (float) $second) * 0.25));

        return new HeaderDetection((int) $best['row'], round($confidence, 4), array_slice($candidates, 0, 5));
    }

    /** @param array<int,array<int,mixed>> $rows */
    private function nextNonEmptyRow(array $rows, int $currentIndex): ?array
    {
        foreach ($rows as $index => $row) {
            if ((int) $index <= $currentIndex) {
                continue;
            }
            if ($this->nonEmpty($row)) {
                return array_values($row);
            }
        }
        return null;
    }

    /** @param array<int,mixed> $row */
    private function nonEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return true;
            }
        }
        return false;
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function looksDate(string $value): bool
    {
        if ($value === '' || is_numeric($value)) {
            return false;
        }
        return preg_match('/^\d{1,4}[-\/.]\d{1,2}[-\/.]\d{1,4}(?:\s+\d{1,2}:\d{2}(?::\d{2})?)?$/', $value) === 1;
    }
}
