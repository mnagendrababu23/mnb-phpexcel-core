<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Reader\Options;

enum RowErrorPolicy: string
{
    case Throw = 'throw';
    case Skip = 'skip';
    case Collect = 'collect';
    case Callback = 'callback';

    public static function fromMixed(self|string|null $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $normalized = strtolower(trim((string) ($value ?? self::Throw->value)));
        return match ($normalized) {
            'throw', 'fail', 'stop' => self::Throw,
            'skip', 'ignore' => self::Skip,
            'collect', 'continue' => self::Collect,
            'callback', 'handle' => self::Callback,
            default => throw new \InvalidArgumentException('Unknown row error policy: ' . $normalized),
        };
    }
}
