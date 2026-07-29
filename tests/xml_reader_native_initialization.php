<?php

declare(strict_types=1);

namespace {
    if (!class_exists('XMLReader')) {
        /** Minimal native XMLReader stand-in for environments without ext-xmlreader. */
        final class XMLReader
        {
            private bool $positioned = false;
            private bool $finished = false;

            public function open(string $uri, ?string $encoding = null, int $flags = 0): bool
            {
                $this->positioned = false;
                $this->finished = false;
                return true;
            }

            public function XML(string $source, ?string $encoding = null, int $flags = 0): bool
            {
                return $this->open('memory://xml', $encoding, $flags);
            }

            public function read(): bool
            {
                if ($this->finished) {
                    $this->positioned = false;
                    return false;
                }

                $this->positioned = true;
                $this->finished = true;
                return true;
            }

            public function close(): bool
            {
                $this->positioned = false;
                return true;
            }

            public function getAttribute(string $name): ?string { return null; }
            public function moveToFirstAttribute(): bool { return false; }
            public function moveToNextAttribute(): bool { return false; }
            public function moveToElement(): bool { return false; }
            public function readOuterXml(): string { return '<root />'; }

            public function __get(string $name): mixed
            {
                if (!$this->positioned) {
                    throw new \Error('Failed to read property due to libxml error');
                }

                return match ($name) {
                    'nodeType' => 1,
                    'name', 'localName' => 'root',
                    'value' => '',
                    'depth' => 0,
                    'isEmptyElement' => true,
                    'hasAttributes' => false,
                    default => null,
                };
            }
        }
    }

    require dirname(__DIR__) . '/src/Support/Xml/XmlReader.php';

    $reader = new \Mnb\PHPExcel\Support\Xml\XmlReader();

    if (!$reader->XML('<root />')) {
        throw new \RuntimeException('Unable to initialize XML reader.');
    }
    if ($reader->nodeType !== \Mnb\PHPExcel\Support\Xml\XmlReader::NONE) {
        throw new \RuntimeException('Reader must remain unpositioned until read().');
    }
    if (!$reader->read()) {
        throw new \RuntimeException('Expected first XML node.');
    }
    if ($reader->localName !== 'root') {
        throw new \RuntimeException('Unexpected first element name: ' . $reader->localName);
    }
    if ($reader->read()) {
        throw new \RuntimeException('Expected end of XML stream.');
    }
    if ($reader->nodeType !== \Mnb\PHPExcel\Support\Xml\XmlReader::NONE) {
        throw new \RuntimeException('Reader state must reset after EOF.');
    }

    echo "xml_reader_native_initialization: OK\n";
}
