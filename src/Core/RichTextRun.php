<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Core;

final class RichTextRun implements \JsonSerializable
{
    /** @param array<string,mixed> $style */
    public function __construct(
        public readonly string $text,
        public readonly array $style = []
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $style = $data;
        unset($style['text']);
        return new self((string) ($data['text'] ?? ''), $style);
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return ['text' => $this->text] + $this->style;
    }
}
