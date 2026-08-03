<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Core;

/**
 * Normalizes reader-facing and writer-facing style arrays into one stable shape.
 *
 * The canonical keys are: font, fill, border, alignment, format, and protection.
 * Read-only OOXML identifiers such as style_index and number_format_id are removed.
 */
final class StyleNormalizer
{
    /** @param array<string,mixed> $style @return array<string,mixed> */
    public static function normalize(array $style): array
    {
        $style = self::snakeKeys($style);

        foreach (['style_index', 'style_id', 'index', 'xf_id', 'number_format_id', 'font_id', 'fill_id', 'border_id'] as $key) {
            unset($style[$key]);
        }
        foreach (array_keys($style) as $key) {
            if (str_starts_with($key, 'apply_')) {
                unset($style[$key]);
            }
        }

        if (!array_key_exists('format', $style)) {
            foreach (['number_format', 'number_format_code', 'format_code'] as $alias) {
                if (array_key_exists($alias, $style)) {
                    $style['format'] = $style[$alias];
                    break;
                }
            }
        }
        unset($style['number_format'], $style['number_format_code'], $style['format_code']);

        if (array_key_exists('borders', $style) && !array_key_exists('border', $style)) {
            $style['border'] = $style['borders'];
        }
        unset($style['borders']);

        if (isset($style['font']) && is_array($style['font'])) {
            $style['font'] = self::font($style['font']);
        }
        if (array_key_exists('fill', $style)) {
            $style['fill'] = self::fill($style['fill']);
        } elseif (array_key_exists('background_color', $style)) {
            $style['fill'] = self::fill($style['background_color']);
        }
        unset($style['background_color']);

        if (array_key_exists('border', $style)) {
            $style['border'] = self::border($style['border']);
        }

        $alignment = is_array($style['alignment'] ?? null) ? $style['alignment'] : [];
        foreach (['horizontal', 'vertical', 'wrap_text', 'wraptext', 'text_rotation', 'shrink_to_fit', 'indent', 'reading_order'] as $key) {
            if (array_key_exists($key, $style) && !array_key_exists($key, $alignment)) {
                $alignment[$key] = $style[$key];
                unset($style[$key]);
            }
        }
        if ($alignment !== []) {
            $style['alignment'] = self::alignment($alignment);
        }

        if (isset($style['protection']) && is_array($style['protection'])) {
            $style['protection'] = self::protection($style['protection']);
        }

        foreach ($style as $key => $value) {
            if ($value === [] || $value === null || $value === '') {
                unset($style[$key]);
            }
        }

        return self::sortRecursive($style);
    }

    /** @param array<string,mixed> $font @return array<string,mixed> */
    private static function font(array $font): array
    {
        $font = self::snakeKeys($font);
        if (array_key_exists('color', $font)) {
            $font['color'] = self::color($font['color']);
        }
        if (isset($font['vert_align']) && !isset($font['vertical_align'])) {
            $font['vertical_align'] = $font['vert_align'];
        }
        unset($font['vert_align']);
        foreach (['bold', 'italic', 'strike', 'outline', 'shadow', 'condense', 'extend'] as $flag) {
            if (array_key_exists($flag, $font)) {
                $font[$flag] = (bool) $font[$flag];
            }
        }
        if (isset($font['size']) && is_numeric($font['size'])) {
            $font['size'] = (float) $font['size'];
        }
        return self::sortRecursive(array_filter($font, static fn (mixed $value): bool => $value !== [] && $value !== null && $value !== ''));
    }

    /** @return array<string,mixed>|false */
    private static function fill(mixed $fill): array|false
    {
        if ($fill === false || $fill === null || $fill === '' || $fill === 'none') {
            return false;
        }
        if (is_string($fill)) {
            return ['type' => 'pattern', 'pattern' => 'solid', 'foreground' => self::color($fill)];
        }
        if (!is_array($fill)) {
            return false;
        }

        $fill = self::snakeKeys($fill);
        $type = strtolower((string) ($fill['type'] ?? 'pattern'));
        if ($type === 'gradient' || isset($fill['stops'])) {
            $result = ['type' => 'gradient'];
            foreach (['gradient_type', 'degree', 'left', 'right', 'top', 'bottom'] as $key) {
                if (array_key_exists($key, $fill)) {
                    $result[$key] = $fill[$key];
                }
            }
            $result['stops'] = [];
            foreach ((array) ($fill['stops'] ?? []) as $stop) {
                if (!is_array($stop)) {
                    continue;
                }
                $item = ['position' => isset($stop['position']) && is_numeric($stop['position']) ? (float) $stop['position'] : 0.0];
                if (array_key_exists('color', $stop)) {
                    $item['color'] = self::color($stop['color']);
                }
                $result['stops'][] = $item;
            }
            return self::sortRecursive($result);
        }

        $pattern = (string) ($fill['pattern'] ?? $fill['pattern_type'] ?? $fill['fill_type'] ?? '');
        $foreground = $fill['foreground'] ?? $fill['fg_color'] ?? $fill['start_color'] ?? $fill['color'] ?? null;
        $background = $fill['background'] ?? $fill['bg_color'] ?? $fill['end_color'] ?? null;
        if ($pattern === '') {
            $pattern = $foreground !== null ? 'solid' : 'none';
        }
        $result = ['type' => 'pattern', 'pattern' => $pattern];
        if ($foreground !== null) {
            $result['foreground'] = self::color($foreground);
        }
        if ($background !== null) {
            $result['background'] = self::color($background);
        }
        if ($pattern === 'none' && !isset($result['foreground'], $result['background'])) {
            return false;
        }
        return self::sortRecursive($result);
    }

    /** @return array<string,mixed>|false */
    private static function border(mixed $border): array|false
    {
        if ($border === false || $border === null || $border === '' || $border === 'none') {
            return false;
        }
        if (is_string($border)) {
            $border = ['all' => ['style' => $border]];
        }
        if (!is_array($border)) {
            return false;
        }
        $border = self::snakeKeys($border);

        $sides = ['left', 'right', 'top', 'bottom', 'diagonal', 'vertical', 'horizontal', 'start', 'end'];
        $all = $border['all'] ?? $border['all_borders'] ?? null;
        if ($all === null && (isset($border['style']) || isset($border['color']))) {
            $all = ['style' => $border['style'] ?? 'thin', 'color' => $border['color'] ?? null];
        }
        $result = [];
        foreach ($sides as $side) {
            $definition = $border[$side] ?? ($side !== 'diagonal' ? $all : null);
            if ($definition === null || $definition === false || $definition === []) {
                continue;
            }
            if (is_string($definition)) {
                $definition = ['style' => $definition];
            }
            if (!is_array($definition)) {
                continue;
            }
            $definition = self::snakeKeys($definition);
            $item = [];
            if (isset($definition['style']) && $definition['style'] !== '') {
                $item['style'] = (string) $definition['style'];
            }
            if (array_key_exists('color', $definition) && $definition['color'] !== null) {
                $item['color'] = self::color($definition['color']);
            }
            if ($item !== []) {
                $result[$side] = $item;
            }
        }
        foreach (['diagonal_up', 'diagonal_down', 'outline'] as $flag) {
            if (array_key_exists($flag, $border)) {
                $result[$flag] = (bool) $border[$flag];
            }
        }
        return $result === [] ? false : self::sortRecursive($result);
    }

    /** @param array<string,mixed> $alignment @return array<string,mixed> */
    private static function alignment(array $alignment): array
    {
        $alignment = self::snakeKeys($alignment);
        if (isset($alignment['wraptext']) && !isset($alignment['wrap_text'])) {
            $alignment['wrap_text'] = $alignment['wraptext'];
        }
        if (isset($alignment['textrotation']) && !isset($alignment['text_rotation'])) {
            $alignment['text_rotation'] = $alignment['textrotation'];
        }
        if (isset($alignment['shrinktofit']) && !isset($alignment['shrink_to_fit'])) {
            $alignment['shrink_to_fit'] = $alignment['shrinktofit'];
        }
        unset($alignment['wraptext'], $alignment['textrotation'], $alignment['shrinktofit']);
        foreach (['wrap_text', 'shrink_to_fit', 'justify_last_line', 'merge_cell'] as $flag) {
            if (array_key_exists($flag, $alignment)) {
                $alignment[$flag] = (bool) $alignment[$flag];
            }
        }
        foreach (['text_rotation', 'indent', 'relative_indent', 'reading_order'] as $number) {
            if (isset($alignment[$number]) && is_numeric($alignment[$number])) {
                $alignment[$number] = (int) $alignment[$number];
            }
        }
        return self::sortRecursive(array_filter($alignment, static fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    /** @param array<string,mixed> $protection @return array<string,mixed> */
    private static function protection(array $protection): array
    {
        $protection = self::snakeKeys($protection);
        foreach (['locked', 'hidden'] as $flag) {
            if (array_key_exists($flag, $protection)) {
                $protection[$flag] = (bool) $protection[$flag];
            }
        }
        return self::sortRecursive($protection);
    }

    /** @return array<string,mixed>|string|int|float|bool */
    public static function color(mixed $color): array|string|int|float|bool
    {
        if (is_string($color)) {
            $value = strtoupper(ltrim(trim($color), '#'));
            if (preg_match('/^[0-9A-F]{6}$/', $value) === 1) {
                return ['rgb' => 'FF' . $value];
            }
            if (preg_match('/^[0-9A-F]{8}$/', $value) === 1) {
                return ['rgb' => $value];
            }
            return $color;
        }
        if (!is_array($color)) {
            return $color;
        }
        $color = self::snakeKeys($color);
        if (isset($color['color']) && !isset($color['rgb'])) {
            return self::color($color['color']);
        }
        $result = [];
        foreach (['rgb', 'indexed', 'theme', 'tint', 'auto'] as $key) {
            if (!array_key_exists($key, $color)) {
                continue;
            }
            $value = $color[$key];
            if ($key === 'rgb' && is_string($value)) {
                $value = strtoupper(ltrim($value, '#'));
                if (strlen($value) === 6) {
                    $value = 'FF' . $value;
                }
            }
            $result[$key] = $value;
        }
        return $result !== [] ? self::sortRecursive($result) : $color;
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    private static function snakeKeys(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $name = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', (string) $key));
            $result[$name] = is_array($value) ? self::snakeKeys($value) : $value;
        }
        return $result;
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    private static function sortRecursive(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = self::sortRecursive($value);
            }
        }
        ksort($values);
        return $values;
    }
}
