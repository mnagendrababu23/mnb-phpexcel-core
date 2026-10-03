<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Metadata;

use ArrayAccess;
use JsonSerializable;

/** Typed, IDE-friendly view over the portable metadata schema. */
final class MetadataResult implements ArrayAccess, JsonSerializable
{
    /** @param array<string,mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    public function schemaVersion(): string { return (string) ($this->data['schema_version'] ?? '1.0'); }
    public function status(): string { return (string) ($this->data['status'] ?? 'unknown'); }
    public function profile(): string { return (string) ($this->data['profile'] ?? 'standard'); }
    public function format(): string { return (string) ($this->data['format'] ?? ''); }
    public function formatVariant(): string { return (string) ($this->data['format_variant'] ?? ''); }
    public function mimeType(): string { return (string) ($this->data['mime_type'] ?? ''); }

    /** @return array<string,mixed> */
    public function section(string $name): array
    {
        $value = $this->data[$name] ?? [];
        return is_array($value) ? $value : [];
    }

    /** @return array<string,mixed> */
    public function file(): array { return $this->section('file'); }
    /** @return array<string,mixed> */
    public function workbook(): array { return $this->section('workbook'); }
    /** @return array<string,mixed> */
    public function document(): array { return $this->section('document'); }
    /** @return array<string,mixed> */
    public function security(): array { return $this->section('security'); }
    /** @return array<string,mixed> */
    public function statistics(): array { return $this->section('statistics'); }
    /** @return array<string,mixed> */
    public function capabilities(): array { return (array) ($this->data['capabilities'] ?? []); }

    public function sheetCount(): int { return (int) ($this->workbook()['sheet_count'] ?? 0); }
    public function title(): ?string { return $this->nullableString($this->document()['title'] ?? null); }
    public function author(): ?string { return $this->nullableString($this->document()['creator'] ?? null); }
    public function company(): ?string { return $this->nullableString($this->section('application')['company'] ?? null); }

    /** @return list<array<string,mixed>> */
    public function sheets(): array
    {
        $items = $this->workbook()['sheets'] ?? $this->workbook()['items'] ?? [];
        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    /** @return list<string> */
    public function warnings(): array { return array_values(array_map('strval', (array) ($this->data['warnings'] ?? []))); }
    /** @return list<string> */
    public function errors(): array { return array_values(array_map('strval', (array) ($this->data['errors'] ?? []))); }

    /** @return array<string,mixed> */
    public function toArray(): array { return $this->data; }
    /** @return array<string,mixed> */
    public function jsonSerialize(): array { return $this->data; }

    public function offsetExists(mixed $offset): bool { return is_string($offset) && array_key_exists($offset, $this->data); }
    public function offsetGet(mixed $offset): mixed { return is_string($offset) ? ($this->data[$offset] ?? null) : null; }
    public function offsetSet(mixed $offset, mixed $value): void { throw new \LogicException('MetadataResult is immutable.'); }
    public function offsetUnset(mixed $offset): void { throw new \LogicException('MetadataResult is immutable.'); }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        return (string) $value;
    }
}
