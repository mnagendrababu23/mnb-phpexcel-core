<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Reader\Plugin;

use Mnb\PHPExcel\Contracts\ReaderPluginInterface;
use Mnb\PHPExcel\Reader\IterableReaderInterface;
use Mnb\PHPExcel\Support\MnbExcelException;

/** @internal */
final class PluginReaderAdapter implements IterableReaderInterface
{
    public function __construct(private readonly ReaderPluginInterface $plugin)
    {
    }

    /** @return list<list<mixed>> */
    public function readSheet(string $path, int|string $sheet = 1, array $options = []): array
    {
        return array_values(iterator_to_array($this->iterateSheet($path, $sheet, $options), true));
    }

    /** @return \Generator<int,list<mixed>> */
    public function iterateSheet(string $path, int|string $sheet = 1, array $options = []): iterable
    {
        if ($sheet !== 1 && $sheet !== '1' && $sheet !== 'Sheet1') {
            throw new MnbExcelException('This reader plugin exposes one row stream. Pass sheet selection through plugin options when supported.');
        }
        $position = 0;
        foreach ($this->plugin->read($path, $options) as $index => $row) {
            yield is_int($index) ? $index : $position => is_array($row) ? array_values($row) : [$row];
            $position++;
        }
    }
}
