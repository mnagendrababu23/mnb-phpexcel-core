<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Core;

final class RichText implements \Stringable, \JsonSerializable
{
    /** @param list<RichTextRun> $runs */
    public function __construct(public readonly array $runs)
    {
    }

    /** @param list<array<string,mixed>> $runs */
    public static function fromArray(array $runs): self
    {
        return new self(array_values(array_map(
            static fn (array $run): RichTextRun => RichTextRun::fromArray($run),
            $runs
        )));
    }

    public static function plain(string $text): self
    {
        return new self([new RichTextRun($text)]);
    }

    public function text(): string
    {
        $text = '';
        foreach ($this->runs as $run) {
            $text .= $run->text;
        }
        return $text;
    }

    public function __toString(): string
    {
        return $this->text();
    }

    /** @return array{text:string,runs:list<array<string,mixed>>} */
    public function jsonSerialize(): array
    {
        return [
            'text' => $this->text(),
            'runs' => array_map(static fn (RichTextRun $run): array => $run->jsonSerialize(), $this->runs),
        ];
    }
}
