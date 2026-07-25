<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Reader\Options;

enum ReadMode: string
{
    case Auto = 'auto';
    case Normal = 'normal';
    case Streaming = 'streaming';

    public static function fromMixed(self|string|null $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return match (strtolower(trim((string) ($value ?? self::Auto->value)))) {
            '', 'auto' => self::Auto,
            'normal', 'buffered', 'small' => self::Normal,
            'stream', 'streaming', 'large', 'low_memory' => self::Streaming,
            default => throw new \InvalidArgumentException('Unknown read mode.'),
        };
    }
}
