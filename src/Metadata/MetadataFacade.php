<?php
declare(strict_types=1);

namespace Mnb\PHPExcel\Metadata;

/**
 * Shared developer-friendly metadata facade used by standalone format packages.
 * Format packages inject native reader/writer/sanitizer callbacks.
 */
final class MetadataFacade
{
    /** @var array<string,array<string,mixed>> */
    private static array $memoryCache = [];
    /** @var list<string>|null */
    private ?array $sections = null;
    /** @var array<string,mixed> */
    private array $options;
    /** @var array<string,mixed> */
    private array $pendingChanges = [];
    private ?MetadataResult $result = null;

    /** @param callable(string,array<string,mixed>):array<string,mixed> $reader */
    public function __construct(
        private readonly string $path,
        private readonly mixed $reader,
        private readonly mixed $writer = null,
        private readonly mixed $sanitizer = null,
        array $options = []
    ) { $this->options = $options; }

    public function quick(): self { return $this->profile(MetadataProfile::QUICK); }
    public function standard(): self { return $this->profile(MetadataProfile::STANDARD); }
    public function full(): self { return $this->profile(MetadataProfile::FULL); }
    public function forensic(): self { return $this->profile(MetadataProfile::FORENSIC); }

    public function profile(string $profile): self {
        $c=clone $this; $c->options['profile']=MetadataProfile::normalize($profile); $c->result=null; return $c;
    }

    /** @param list<string> $sections */
    public function only(array $sections): self {
        $sections=array_values(array_unique(array_map('strval',$sections)));
        foreach($sections as $s) if(!in_array($s,MetadataReport::SECTIONS,true)) throw new \InvalidArgumentException('Unknown metadata section: '.$s);
        $c=clone $this; $c->sections=$sections; $c->options['only_sections']=$sections; $c->result=null; return $c;
    }

    public function cache(bool $enabled=true): self { $c=clone $this; $c->options['metadata_cache']=$enabled; $c->result=null; return $c; }
    public function withHash(bool $enabled=true): self { $c=clone $this; $c->options['include_hash']=$enabled; $c->result=null; return $c; }

    public function read(): MetadataResult {
        if($this->result!==null) return $this->result;
        $key=$this->fingerprint((bool)($this->options['include_hash']??false));
        if(($this->options['metadata_cache']??true) && isset(self::$memoryCache[$key])) return $this->result=new MetadataResult(self::$memoryCache[$key]);
        $data=($this->reader)($this->path,$this->options);
        if($this->sections!==null){
            $keep=array_flip(array_merge(['schema_version','status','profile','format','format_variant','mime_type','capabilities','warnings','errors'],$this->sections));
            $data=array_intersect_key($data,$keep);
        }
        if($this->options['metadata_cache']??true) self::$memoryCache[$key]=$data;
        return $this->result=new MetadataResult($data);
    }

    /** @return array<string,mixed> */
    public function toArray(): array { return $this->read()->toArray(); }
    public function format(): string { return $this->read()->format(); }
    public function sheetCount(): int { return $this->read()->sheetCount(); }
    public function title(): ?string { return $this->read()->title(); }
    public function author(): ?string { return $this->read()->author(); }
    public function company(): ?string { return $this->read()->company(); }
    /** @return list<array<string,mixed>> */
    public function sheets(): array { return $this->read()->sheets(); }

    /** @param array<string,mixed> $changes */
    public function update(array $changes): self {
        if(!is_callable($this->writer)) throw new \LogicException('This format does not support embedded metadata updates.');
        $c=clone $this; $c->pendingChanges=array_replace($c->pendingChanges,$changes); return $c;
    }

    public function save(?string $destination=null): string {
        if(!is_callable($this->writer)) throw new \LogicException('This format does not support embedded metadata updates.');
        if($this->pendingChanges===[]) throw new \LogicException('No metadata changes were supplied. Call update() first.');
        $destination??=$this->path; ($this->writer)($this->path,$destination,$this->pendingChanges,$this->options); self::clearCache(); return $destination;
    }

    public function removePersonalInfo(?string $destination=null): string {
        if(!is_callable($this->sanitizer)) throw new \LogicException('This format does not contain a supported embedded personal-metadata store.');
        $destination??=$this->path; ($this->sanitizer)($this->path,$destination,$this->options); self::clearCache(); return $destination;
    }

    /** @return array<string,mixed> */
    public function diff(string|MetadataResult|self $other): array {
        $left=$this->read()->toArray();
        if($other instanceof self) $right=$other->read()->toArray();
        elseif($other instanceof MetadataResult) $right=$other->toArray();
        else { $clone=new self($other,$this->reader,$this->writer,$this->sanitizer,$this->options); $right=$clone->read()->toArray(); }
        return MetadataDiff::between($left,$right);
    }

    public function fingerprint(bool $withHash=false): string {
        $s=@stat($this->path)?:[];
        $parts=[realpath($this->path)?:$this->path,(string)($s['size']??-1),(string)($s['mtime']??-1),json_encode($this->options)?:'{}',json_encode($this->sections)?:'null'];
        if($withHash&&is_file($this->path)) $parts[]=hash_file('sha256',$this->path)?:'';
        return hash('sha256',implode("\0",$parts));
    }

    public static function clearCache(): void { self::$memoryCache=[]; }
}
