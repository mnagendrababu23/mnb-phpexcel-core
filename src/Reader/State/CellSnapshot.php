<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Reader\State;

use Mnb\PHPExcel\Core\RichText;

final class CellSnapshot implements \JsonSerializable
{
    /**
     * @param array<string,mixed> $style
     * @param list<array<string,mixed>> $comments
     * @param list<array<string,mixed>> $hyperlinks
     * @param list<array<string,mixed>> $images
     */
    public function __construct(
        public readonly string $cell,
        public readonly mixed $value,
        public readonly ?string $formula = null,
        public readonly mixed $cachedValue = null,
        public readonly mixed $calculatedValue = null,
        public readonly ?RichText $richText = null,
        public readonly array $style = [],
        public readonly array $comments = [],
        public readonly array $hyperlinks = [],
        public readonly array $images = []
    ) {
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'cell' => $this->cell,
            'value' => $this->value,
            'formula' => $this->formula,
            'cached_value' => $this->cachedValue,
            'calculated_value' => $this->calculatedValue,
            'rich_text' => $this->richText,
            'style' => $this->style,
            'comments' => $this->comments,
            'hyperlinks' => $this->hyperlinks,
            'images' => $this->images,
        ];
    }
}
