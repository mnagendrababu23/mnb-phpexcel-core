<?php

declare(strict_types=1);

namespace Mnb\PHPExcel\Support\Zip;

/**
 * ZipArchive-compatible adapter. It delegates to ext-zip when available and
 * falls back to a pure-PHP ZIP reader/writer for stored and deflated entries.
 */
final class ZipArchive
{
    public const CREATE = 1;
    public const OVERWRITE = 8;

    public int $numFiles = 0;

    private ?\ZipArchive $native = null;
    private string $path = '';
    private bool $writeMode = false;
    /** @var array<string,array{name:string,data?:string,path?:string,method?:int,crc?:int,size?:int,comp_size?:int,offset?:int,flags?:int}> */
    private array $entries = [];
    /** @var list<string> */
    private array $order = [];
    private string $archiveBytes = '';

    public function open(string $filename, int $flags = 0): bool|int
    {
        $this->close();
        $this->path = $filename;
        if (class_exists(\ZipArchive::class)) {
            $this->native = new \ZipArchive();
            $result = $this->native->open($filename, $flags);
            if ($result === true) {
                $this->numFiles = $this->native->numFiles;
            }
            return $result;
        }

        $this->writeMode = ($flags & self::CREATE) !== 0 || ($flags & self::OVERWRITE) !== 0;
        if (($flags & self::OVERWRITE) !== 0) {
            $this->entries = [];
            $this->order = [];
            $this->numFiles = 0;
            return true;
        }
        if (!is_file($filename)) {
            if (($flags & self::CREATE) !== 0) {
                return true;
            }
            return false;
        }
        $bytes = file_get_contents($filename);
        if ($bytes === false) {
            return false;
        }
        $this->archiveBytes = $bytes;
        try {
            $this->parseCentralDirectory($bytes);
            return true;
        } catch (\Throwable) {
            $this->entries = [];
            $this->order = [];
            $this->numFiles = 0;
            return false;
        }
    }

    public function close(): bool
    {
        if ($this->native !== null) {
            $result = $this->native->close();
            $this->native = null;
            $this->numFiles = 0;
            return $result;
        }
        if ($this->writeMode && $this->path !== '') {
            $this->writeArchive();
        }
        $this->path = '';
        $this->writeMode = false;
        $this->entries = [];
        $this->order = [];
        $this->archiveBytes = '';
        $this->numFiles = 0;
        return true;
    }

    public function addFromString(string $name, string $content): bool
    {
        if ($this->native !== null) {
            $ok = $this->native->addFromString($name, $content);
            $this->numFiles = $this->native->numFiles;
            return $ok;
        }
        $name = $this->normalizeName($name);
        if (!isset($this->entries[$name])) {
            $this->order[] = $name;
        }
        $this->entries[$name] = ['name' => $name, 'data' => $content];
        $this->numFiles = count($this->order);
        $this->writeMode = true;
        return true;
    }

    public function addFile(string $filepath, string $entryname = ''): bool
    {
        if ($this->native !== null) {
            $ok = $this->native->addFile($filepath, $entryname !== '' ? $entryname : basename($filepath));
            $this->numFiles = $this->native->numFiles;
            return $ok;
        }
        if (!is_file($filepath)) {
            return false;
        }
        $name = $this->normalizeName($entryname !== '' ? $entryname : basename($filepath));
        if (!isset($this->entries[$name])) {
            $this->order[] = $name;
        }
        $this->entries[$name] = ['name' => $name, 'path' => $filepath];
        $this->numFiles = count($this->order);
        $this->writeMode = true;
        return true;
    }

    public function getFromName(string $name): string|false
    {
        if ($this->native !== null) {
            return $this->native->getFromName($name);
        }
        $name = $this->normalizeName($name);
        if (!isset($this->entries[$name])) {
            return false;
        }
        $entry = $this->entries[$name];
        if (array_key_exists('data', $entry)) {
            return (string) $entry['data'];
        }
        if (isset($entry['path'])) {
            $data = file_get_contents((string) $entry['path']);
            return $data === false ? false : $data;
        }
        return $this->inflateEntry($entry);
    }

    /** @return resource|false */
    public function getStream(string $name)
    {
        if ($this->native !== null) {
            return $this->native->getStream($name);
        }
        $data = $this->getFromName($name);
        if ($data === false) {
            return false;
        }
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            return false;
        }
        fwrite($stream, $data);
        rewind($stream);
        return $stream;
    }

    public function locateName(string $name, int $flags = 0): int|false
    {
        if ($this->native !== null) {
            return $this->native->locateName($name, $flags);
        }
        $name = $this->normalizeName($name);
        $index = array_search($name, $this->order, true);
        return $index === false ? false : $index;
    }

    public function getNameIndex(int $index, int $flags = 0): string|false
    {
        if ($this->native !== null) {
            return $this->native->getNameIndex($index, $flags);
        }
        return $this->order[$index] ?? false;
    }

    /** @return array<string,mixed>|false */
    public function statIndex(int $index, int $flags = 0): array|false
    {
        if ($this->native !== null) {
            return $this->native->statIndex($index, $flags);
        }
        $name = $this->order[$index] ?? null;
        return $name === null ? false : $this->statName($name, $flags);
    }

    /** @return array<string,mixed>|false */
    public function statName(string $name, int $flags = 0): array|false
    {
        if ($this->native !== null) {
            return $this->native->statName($name, $flags);
        }
        $name = $this->normalizeName($name);
        $entry = $this->entries[$name] ?? null;
        if ($entry === null) {
            return false;
        }
        $dataSize = isset($entry['data']) ? strlen((string) $entry['data']) : (isset($entry['path']) ? (filesize((string) $entry['path']) ?: 0) : (int) ($entry['size'] ?? 0));
        return [
            'name' => $name,
            'index' => (int) array_search($name, $this->order, true),
            'size' => $dataSize,
            'comp_size' => (int) ($entry['comp_size'] ?? $dataSize),
            'crc' => (int) ($entry['crc'] ?? 0),
            'comp_method' => (int) ($entry['method'] ?? 0),
        ];
    }

    public static function nativeAvailable(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    private function parseCentralDirectory(string $bytes): void
    {
        $eocd = strrpos($bytes, "PK\x05\x06");
        if ($eocd === false || strlen($bytes) < $eocd + 22) {
            throw new \RuntimeException('ZIP EOCD not found.');
        }
        $tail = unpack('vdisk/vstart_disk/ventries_disk/ventries/Vsize/Voffset/vcomment', substr($bytes, $eocd + 4, 18));
        if (!is_array($tail)) {
            throw new \RuntimeException('Invalid ZIP EOCD.');
        }
        $offset = (int) $tail['offset'];
        $count = (int) $tail['entries'];
        for ($index = 0; $index < $count; $index++) {
            $fixed = substr($bytes, $offset, 46);
            $meta = unpack('Vsignature/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcomp_size/Vsize/vname_len/vextra_len/vcomment_len/vdisk/vinternal/Vexternal/Vlocal_offset', $fixed);
            if (!is_array($meta) || (int) $meta['signature'] !== 0x02014b50) {
                throw new \RuntimeException('Invalid ZIP central directory.');
            }
            $name = substr($bytes, $offset + 46, (int) $meta['name_len']);
            $name = $this->normalizeName($name);
            $this->entries[$name] = [
                'name' => $name,
                'method' => (int) $meta['method'],
                'crc' => (int) $meta['crc'],
                'size' => (int) $meta['size'],
                'comp_size' => (int) $meta['comp_size'],
                'offset' => (int) $meta['local_offset'],
                'flags' => (int) $meta['flags'],
            ];
            $this->order[] = $name;
            $offset += 46 + (int) $meta['name_len'] + (int) $meta['extra_len'] + (int) $meta['comment_len'];
        }
        $this->numFiles = count($this->order);
    }

    /** @param array<string,mixed> $entry */
    private function inflateEntry(array $entry): string|false
    {
        $offset = (int) ($entry['offset'] ?? -1);
        if ($offset < 0 || substr($this->archiveBytes, $offset, 4) !== "PK\x03\x04") {
            return false;
        }
        $local = unpack('Vsignature/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcomp_size/Vsize/vname_len/vextra_len', substr($this->archiveBytes, $offset, 30));
        if (!is_array($local)) {
            return false;
        }
        $dataOffset = $offset + 30 + (int) $local['name_len'] + (int) $local['extra_len'];
        $compressed = substr($this->archiveBytes, $dataOffset, (int) ($entry['comp_size'] ?? 0));
        return match ((int) ($entry['method'] ?? 0)) {
            0 => $compressed,
            8 => (($value = @gzinflate($compressed)) === false ? false : $value),
            default => false,
        };
    }

    private function writeArchive(): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create ZIP directory.');
        }
        $localData = '';
        $central = '';
        $offset = 0;
        [$dosTime, $dosDate] = $this->dosTimeDate();
        foreach ($this->order as $name) {
            $entry = $this->entries[$name];
            $data = isset($entry['data']) ? (string) $entry['data'] : (isset($entry['path']) ? (string) file_get_contents((string) $entry['path']) : (string) $this->inflateEntry($entry));
            $compressed = function_exists('gzdeflate') ? gzdeflate($data, 6) : false;
            $method = $compressed !== false && strlen($compressed) < strlen($data) ? 8 : 0;
            if ($method === 0) {
                $compressed = $data;
            }
            $crc = crc32($data);
            if ($crc < 0) {
                $crc += 4294967296;
            }
            $nameBytes = $name;
            $flags = 0x0800;
            $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, $flags, $method, $dosTime, $dosDate, $crc, strlen($compressed), strlen($data), strlen($nameBytes), 0);
            $localData .= $localHeader . $nameBytes . $compressed;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 0x031E, 20, $flags, $method, $dosTime, $dosDate, $crc, strlen($compressed), strlen($data), strlen($nameBytes), 0, 0, 0, 0, 0, $offset) . $nameBytes;
            $offset += strlen($localHeader) + strlen($nameBytes) + strlen($compressed);
        }
        $eocd = pack('VvvvvVVv', 0x06054b50, 0, 0, count($this->order), count($this->order), strlen($central), strlen($localData), 0);
        $tmp = $this->path . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $localData . $central . $eocd, LOCK_EX) === false || !rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to write ZIP archive.');
        }
    }

    /** @return array{int,int} */
    private function dosTimeDate(): array
    {
        $now = getdate();
        $year = max(1980, (int) $now['year']);
        $time = ((int) $now['hours'] << 11) | ((int) $now['minutes'] << 5) | ((int) ($now['seconds'] / 2));
        $date = (($year - 1980) << 9) | ((int) $now['mon'] << 5) | (int) $now['mday'];
        return [$time, $date];
    }

    private function normalizeName(string $name): string
    {
        return ltrim(str_replace('\\', '/', $name), '/');
    }
}
