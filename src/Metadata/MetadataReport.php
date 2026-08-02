<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Metadata;

use JsonSerializable;

final class MetadataReport implements JsonSerializable
{
    public const SCHEMA_VERSION = '1.0';

    /** @var list<string> */
    public const SECTIONS = [
        'file',
        'format_details',
        'document',
        'revision',
        'application',
        'workbook',
        'custom_properties',
        'security',
        'macros',
        'named_objects',
        'links',
        'hidden_content',
        'comments_notes',
        'tracked_changes',
        'embedded_objects',
        'calculation',
        'print_settings',
        'validation',
        'pivot_metadata',
        'xml_metadata',
        'statistics',
    ];

    /** @var array<string,mixed> */
    private array $data;

    public function __construct(string $format, string $formatVariant, string $mimeType, string $profile)
    {
        $this->data = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'profile' => MetadataProfile::normalize($profile),
            'format' => strtolower($format),
            'format_variant' => strtolower($formatVariant),
            'mime_type' => $mimeType,
        ];

        foreach (self::SECTIONS as $section) {
            $this->data[$section] = self::section(MetadataSectionState::NOT_SCANNED);
        }
        $this->data['capabilities'] = [];
        $this->data['warnings'] = [];
        $this->data['errors'] = [];
    }

    /** @param array<string,mixed> $data */
    public static function section(string $state, array $data = []): array
    {
        $state = MetadataSectionState::assert($state);
        return array_replace([
            'state' => $state,
            'count' => null,
            'items' => [],
            'truncated' => false,
            'warnings' => [],
        ], $data, ['state' => $state]);
    }

    /** @param array<string,mixed> $data */
    public function setSection(string $name, string $state, array $data = []): self
    {
        if (!in_array($name, self::SECTIONS, true)) {
            throw new \InvalidArgumentException('Unknown metadata section: ' . $name);
        }
        $this->data[$name] = self::section($state, $data);
        return $this;
    }

    /** @param array<string,mixed> $capabilities */
    public function capabilities(array $capabilities): self
    {
        $this->data['capabilities'] = $capabilities;
        return $this;
    }

    public function status(string $status): self
    {
        $this->data['status'] = $status;
        return $this;
    }

    public function warning(string $warning): self
    {
        if ($warning !== '' && !in_array($warning, $this->data['warnings'], true)) {
            $this->data['warnings'][] = $warning;
        }
        return $this;
    }

    public function error(string $error): self
    {
        if ($error !== '' && !in_array($error, $this->data['errors'], true)) {
            $this->data['errors'][] = $error;
        }
        $this->data['status'] = 'error';
        return $this;
    }

    /** @param array<string,mixed> $values */
    public function mergeTopLevel(array $values): self
    {
        foreach ($values as $key => $value) {
            if (in_array($key, self::SECTIONS, true) || in_array($key, ['schema_version'], true)) {
                continue;
            }
            $this->data[$key] = $value;
        }
        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
