<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Reader;

use Mnb\PHPExcel\Compatibility\XlsReader;
use Mnb\PHPExcel\Contracts\ReaderPluginInterface;
use Mnb\PHPExcel\Reader\Plugin\PluginReaderAdapter;
use Mnb\PHPExcel\Support\ErrorCode;
use Mnb\PHPExcel\Support\FileFormatDetector;
use Mnb\PHPExcel\Support\MnbExcelException;

/** Instance-scoped reader registry for long-running workers and plugins. */
final class ReaderRegistry
{
    /** @var array<string,callable():ReaderInterface> */
    private array $factories = [];

    /** @var list<array{priority:int,plugin:ReaderPluginInterface}> */
    private array $plugins = [];

    public static function withBuiltIns(): self
    {
        $registry = new self();
        $builtIns = [
            'csv' => CsvReader::class,
            'json' => JsonReader::class,
            'xml' => XmlReader::class,
            'xlsx' => XlsxReader::class,
            'ods' => OdsReader::class,
            'xls' => XlsReader::class,
        ];
        foreach ($builtIns as $format => $class) {
            if (class_exists($class)) {
                $registry->register($format, static fn(): ReaderInterface => new $class());
            }
        }
        return $registry;
    }

    /** @param callable():ReaderInterface|ReaderInterface $reader */
    public function register(string $format, callable|ReaderInterface $reader): self
    {
        $format = strtolower(trim($format));
        if ($format === '') {
            throw new MnbExcelException('Reader format cannot be empty.');
        }
        $this->factories[$format] = $reader instanceof ReaderInterface
            ? static fn(): ReaderInterface => $reader
            : $reader;
        return $this;
    }

    public function registerPlugin(ReaderPluginInterface $plugin, int $priority = 0): self
    {
        $this->plugins[] = ['priority' => $priority, 'plugin' => $plugin];
        usort($this->plugins, static fn(array $a, array $b): int => $b['priority'] <=> $a['priority']);
        return $this;
    }

    /** @param array<string,mixed> $options */
    public function resolve(string $path, array $options = []): ReaderInterface
    {
        $explicit = strtolower(trim((string) ($options['format'] ?? $options['reader'] ?? 'auto')));
        if ($explicit !== '' && $explicit !== 'auto' && isset($this->factories[$explicit])) {
            return $this->make($explicit);
        }

        foreach ($this->plugins as $entry) {
            if ($entry['plugin']->supports($path, $options)) {
                return new PluginReaderAdapter($entry['plugin']);
            }
        }

        $format = FileFormatDetector::detect($path, $options);
        if (!isset($this->factories[$format])) {
            throw MnbExcelException::withCode(
                'Reader module is not installed for format: ' . $format,
                ErrorCode::UNSUPPORTED_FORMAT,
                ['format' => $format],
                null,
                'Install the matching MNB PHPExcel format package.'
            );
        }
        return $this->make($format);
    }

    /** @return list<string> */
    public function formats(): array
    {
        return array_keys($this->factories);
    }

    public function has(string $format): bool
    {
        return isset($this->factories[strtolower(trim($format))]);
    }

    private function make(string $format): ReaderInterface
    {
        $reader = ($this->factories[$format])();
        if (!$reader instanceof ReaderInterface) {
            throw new MnbExcelException('Reader factory for ' . $format . ' did not return ReaderInterface.');
        }
        return $reader;
    }
}
