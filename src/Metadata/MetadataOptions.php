<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Metadata;

use Mnb\PHPExcel\Support\MnbExcelException;

final class MetadataOptions
{
    /** @param array<string,mixed> $values */
    private function __construct(private readonly array $values)
    {
    }

    /** @param array<string,mixed> $options */
    public static function fromArray(array $options = []): self
    {
        $profile = MetadataProfile::normalize($options['profile'] ?? MetadataProfile::STANDARD);
        $maxItems = (int) ($options['max_items'] ?? 1000);
        if ($maxItems < 1) {
            throw new MnbExcelException('Metadata max_items must be greater than zero.');
        }

        $normalized = array_replace($options, [
            'profile' => $profile,
            'max_items' => min($maxItems, 100000),
            'include_hash' => (bool) ($options['include_hash'] ?? ($profile === MetadataProfile::FORENSIC)),
            'include_package_parts' => (bool) ($options['include_package_parts'] ?? MetadataProfile::atLeast($profile, MetadataProfile::FULL)),
            'include_relationships' => (bool) ($options['include_relationships'] ?? ($profile === MetadataProfile::FORENSIC)),
            'accurate_sheet_counts' => (bool) ($options['accurate_sheet_counts'] ?? MetadataProfile::atLeast($profile, MetadataProfile::FULL)),
        ]);

        return new self($normalized);
    }

    public function profile(): string
    {
        return (string) $this->values['profile'];
    }

    public function atLeast(string $profile): bool
    {
        return MetadataProfile::atLeast($this->profile(), $profile);
    }

    public function maxItems(): int
    {
        return (int) $this->values['max_items'];
    }

    public function includeHash(): bool
    {
        return (bool) $this->values['include_hash'];
    }

    public function includePackageParts(): bool
    {
        return (bool) $this->values['include_package_parts'];
    }

    public function includeRelationships(): bool
    {
        return (bool) $this->values['include_relationships'];
    }

    public function accurateSheetCounts(): bool
    {
        return (bool) $this->values['accurate_sheet_counts'];
    }

    public function password(): string
    {
        return (string) ($this->values['password'] ?? $this->values['xlsx_password'] ?? '');
    }

    public function bool(string $key, bool $default = false): bool
    {
        return array_key_exists($key, $this->values) ? (bool) $this->values[$key] : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        return array_key_exists($key, $this->values) ? (int) $this->values[$key] : $default;
    }

    public function value(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->values;
    }
}
