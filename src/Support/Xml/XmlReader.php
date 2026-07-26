<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Support\Xml;

use Mnb\PHPExcel\Support\Zip\ZipArchive;

/** XMLReader-compatible adapter with a native streaming path and pure-PHP fallback. */
final class XmlReader
{
    public const NONE = 0;
    public const ELEMENT = 1;
    public const ATTRIBUTE = 2;
    public const TEXT = 3;
    public const CDATA = 4;
    public const ENTITY_REF = 5;
    public const ENTITY = 6;
    public const PROCESSING_INSTRUCTION = 7;
    public const COMMENT = 8;
    public const DOC = 9;
    public const DOC_TYPE = 10;
    public const WHITESPACE = 13;
    public const SIGNIFICANT_WHITESPACE = 14;
    public const END_ELEMENT = 15;
    public const END_ENTITY = 16;
    public const XML_DECLARATION = 17;

    public int $nodeType = self::NONE;
    public string $localName = '';
    public string $name = '';
    public string $value = '';
    public int $depth = 0;
    public bool $isEmptyElement = false;
    public bool $hasAttributes = false;

    private ?\XMLReader $native = null;
    /** @var list<array<string,mixed>> */
    private array $events = [];
    private int $position = -1;
    private int $attributeIndex = -1;
    private bool $onAttribute = false;
    /** @var array<string,string> */
    private array $currentAttributes = [];
    /** @var array<string,mixed>|null */
    private ?array $currentElement = null;

    public function __construct()
    {
        if (class_exists(\XMLReader::class)) {
            $this->native = new \XMLReader();
        }
    }

    public function open(string $uri, ?string $encoding = null, int $flags = 0): bool
    {
        if ($this->native !== null) {
            $ok = $this->native->open($uri, $encoding, $flags);
            if ($ok) {
                $this->syncNative();
            }
            return $ok;
        }
        $xml = $this->readUri($uri);
        if ($xml === false) {
            return false;
        }
        return $this->load($xml);
    }

    public function XML(string $source, ?string $encoding = null, int $flags = 0): bool
    {
        if ($this->native !== null) {
            $ok = $this->native->XML($source, $encoding, $flags);
            if ($ok) {
                $this->syncNative();
            }
            return $ok;
        }
        return $this->load($source);
    }

    public function read(): bool
    {
        if ($this->native !== null) {
            $ok = $this->native->read();
            if ($ok) {
                $this->syncNative();
            }
            return $ok;
        }
        $this->position++;
        if (!isset($this->events[$this->position])) {
            $this->resetPublicState();
            return false;
        }
        $this->applyEvent($this->events[$this->position]);
        return true;
    }

    public function getAttribute(string $name): ?string
    {
        if ($this->native !== null) {
            $value = $this->native->getAttribute($name);
            return $value === null ? null : (string) $value;
        }
        if (array_key_exists($name, $this->currentAttributes)) {
            return $this->currentAttributes[$name];
        }
        foreach ($this->currentAttributes as $key => $value) {
            if ($this->local($key) === $name) {
                return $value;
            }
        }
        return null;
    }

    public function moveToFirstAttribute(): bool
    {
        if ($this->native !== null) {
            $ok = $this->native->moveToFirstAttribute();
            if ($ok) {
                $this->syncNative();
            }
            return $ok;
        }
        if ($this->currentAttributes === []) {
            return false;
        }
        $this->attributeIndex = 0;
        $this->onAttribute = true;
        $this->applyAttribute();
        return true;
    }

    public function moveToNextAttribute(): bool
    {
        if ($this->native !== null) {
            $ok = $this->native->moveToNextAttribute();
            if ($ok) {
                $this->syncNative();
            }
            return $ok;
        }
        if (!$this->onAttribute || $this->attributeIndex + 1 >= count($this->currentAttributes)) {
            return false;
        }
        $this->attributeIndex++;
        $this->applyAttribute();
        return true;
    }

    public function moveToElement(): bool
    {
        if ($this->native !== null) {
            $ok = $this->native->moveToElement();
            if ($ok) {
                $this->syncNative();
            }
            return $ok;
        }
        if (!$this->onAttribute || $this->currentElement === null) {
            return false;
        }
        $this->onAttribute = false;
        $this->attributeIndex = -1;
        $this->applyEvent($this->currentElement);
        return true;
    }

    public function readOuterXml(): string
    {
        if ($this->native !== null) {
            return (string) $this->native->readOuterXml();
        }
        return (string) ($this->currentElement['outer'] ?? '');
    }

    public function close(): bool
    {
        if ($this->native !== null) {
            $result = $this->native->close();
            $this->resetPublicState();
            return $result;
        }
        $this->events = [];
        $this->position = -1;
        $this->currentAttributes = [];
        $this->currentElement = null;
        $this->resetPublicState();
        return true;
    }

    public static function nativeAvailable(): bool
    {
        return class_exists(\XMLReader::class);
    }

    private function load(string $xml): bool
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return false;
        }
        try {
            $this->events = $this->tokenize($xml);
            $this->position = -1;
            $this->currentAttributes = [];
            $this->currentElement = null;
            $this->resetPublicState();
            return true;
        } catch (\Throwable) {
            $this->events = [];
            return false;
        }
    }

    /** @return list<array<string,mixed>> */
    private function tokenize(string $xml): array
    {
        preg_match_all('/<!--.*?-->|<\?.*?\?>|<!\[CDATA\[.*?\]\]>|<\/\s*[^>]+>|<[^>]+>|[^<]+/s', $xml, $matches, PREG_OFFSET_CAPTURE);
        $events = [];
        $stack = [];
        $depth = 0;
        foreach ($matches[0] as [$token, $offset]) {
            if ($token === '' || str_starts_with($token, '<!--') || str_starts_with($token, '<?')) {
                continue;
            }
            if (str_starts_with($token, '<![CDATA[')) {
                $events[] = ['type' => self::CDATA, 'name' => '#cdata-section', 'local' => '#cdata-section', 'value' => substr($token, 9, -3), 'depth' => $depth, 'empty' => false, 'attributes' => [], 'outer' => $token];
                continue;
            }
            if ($token[0] !== '<') {
                $value = html_entity_decode($token, ENT_QUOTES | ENT_XML1, 'UTF-8');
                $events[] = ['type' => trim($value) === '' ? self::SIGNIFICANT_WHITESPACE : self::TEXT, 'name' => '#text', 'local' => '#text', 'value' => $value, 'depth' => $depth, 'empty' => false, 'attributes' => [], 'outer' => $token];
                continue;
            }
            if (str_starts_with($token, '</')) {
                $depth = max(0, $depth - 1);
                $name = trim(substr($token, 2, -1));
                $events[] = ['type' => self::END_ELEMENT, 'name' => $name, 'local' => $this->local($name), 'value' => '', 'depth' => $depth, 'empty' => false, 'attributes' => [], 'outer' => $token];
                $endIndex = count($events) - 1;
                $start = array_pop($stack);
                if (is_array($start)) {
                    $events[$start['index']]['outer'] = substr($xml, $start['offset'], $offset + strlen($token) - $start['offset']);
                }
                continue;
            }
            $empty = str_ends_with(rtrim($token), '/>');
            $inside = trim(substr($token, 1, $empty ? -2 : -1));
            if ($inside === '' || $inside[0] === '!') {
                continue;
            }
            if (preg_match('/^([^\s]+)(.*)$/s', $inside, $parts) !== 1) {
                throw new \RuntimeException('Invalid XML element.');
            }
            $name = $parts[1];
            $attributes = $this->parseAttributes($parts[2] ?? '');
            $events[] = ['type' => self::ELEMENT, 'name' => $name, 'local' => $this->local($name), 'value' => '', 'depth' => $depth, 'empty' => $empty, 'attributes' => $attributes, 'outer' => $empty ? $token : ''];
            if (!$empty) {
                $stack[] = ['index' => count($events) - 1, 'offset' => $offset, 'name' => $name];
                $depth++;
            }
        }
        if ($stack !== []) {
            throw new \RuntimeException('Unclosed XML element.');
        }
        return $events;
    }

    /** @return array<string,string> */
    private function parseAttributes(string $source): array
    {
        $attributes = [];
        preg_match_all('/([^\s=]+)\s*=\s*("([^"]*)"|\'([^\']*)\')/s', $source, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $attributes[$match[1]] = html_entity_decode($match[3] !== '' ? $match[3] : $match[4], ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        return $attributes;
    }

    private function readUri(string $uri): string|false
    {
        if (str_starts_with($uri, 'zip://')) {
            $spec = substr($uri, 6);
            $hash = strrpos($spec, '#');
            if ($hash === false) {
                return false;
            }
            $path = substr($spec, 0, $hash);
            $entry = substr($spec, $hash + 1);
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                return false;
            }
            try {
                return $zip->getFromName($entry);
            } finally {
                $zip->close();
            }
        }
        return file_get_contents($uri);
    }

    /** @param array<string,mixed> $event */
    private function applyEvent(array $event): void
    {
        $this->nodeType = (int) $event['type'];
        $this->name = (string) $event['name'];
        $this->localName = (string) $event['local'];
        $this->value = (string) $event['value'];
        $this->depth = (int) $event['depth'];
        $this->isEmptyElement = (bool) $event['empty'];
        $this->currentAttributes = (array) $event['attributes'];
        $this->hasAttributes = $this->currentAttributes !== [];
        $this->currentElement = $this->nodeType === self::ELEMENT ? $event : null;
        $this->onAttribute = false;
        $this->attributeIndex = -1;
    }

    private function applyAttribute(): void
    {
        $keys = array_keys($this->currentAttributes);
        $name = $keys[$this->attributeIndex] ?? '';
        $this->nodeType = self::ATTRIBUTE;
        $this->name = $name;
        $this->localName = $this->local($name);
        $this->value = $this->currentAttributes[$name] ?? '';
        $this->isEmptyElement = false;
        $this->hasAttributes = false;
    }

    private function syncNative(): void
    {
        if ($this->native === null) {
            return;
        }
        $this->nodeType = $this->native->nodeType;
        $this->name = (string) $this->native->name;
        $this->localName = (string) $this->native->localName;
        $this->value = (string) $this->native->value;
        $this->depth = $this->native->depth;
        $this->isEmptyElement = $this->native->isEmptyElement;
        $this->hasAttributes = $this->native->hasAttributes;
    }

    private function resetPublicState(): void
    {
        $this->nodeType = self::NONE;
        $this->name = '';
        $this->localName = '';
        $this->value = '';
        $this->depth = 0;
        $this->isEmptyElement = false;
        $this->hasAttributes = false;
    }

    private function local(string $name): string
    {
        $position = strrpos($name, ':');
        return $position === false ? $name : substr($name, $position + 1);
    }
}
