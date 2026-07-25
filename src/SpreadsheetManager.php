<?php

declare(strict_types=1);

namespace Mnb\PHPExcel;

use Mnb\PHPExcel\Contracts\ReaderPluginInterface;
use Mnb\PHPExcel\Reader\Options\ReaderOptions;
use Mnb\PHPExcel\Reader\ReaderRegistry;
use Mnb\PHPExcel\Reader\ReadSession;

/** Lightweight, instance-based entry point for modular installations. */
final class SpreadsheetManager
{
    public function __construct(private readonly ReaderRegistry $readers)
    {
    }

    public static function create(?ReaderRegistry $readers = null): self
    {
        return new self($readers ?? ReaderRegistry::withBuiltIns());
    }

    /** @param array<string,mixed>|ReaderOptions $options */
    public function read(string $path, array|ReaderOptions $options = []): ReadSession
    {
        $values = $options instanceof ReaderOptions ? $options->toArray() : ReaderOptions::fromArray($options)->toArray();
        return new ReadSession($path, $this->readers->resolve($path, $values), $values);
    }

    public function registerReader(string $format, callable|\Mnb\PHPExcel\Reader\ReaderInterface $reader): self
    {
        $this->readers->register($format, $reader);
        return $this;
    }

    public function registerReaderPlugin(ReaderPluginInterface $plugin, int $priority = 0): self
    {
        $this->readers->registerPlugin($plugin, $priority);
        return $this;
    }

    /** @return list<string> */
    public function formats(): array
    {
        return $this->readers->formats();
    }
}
