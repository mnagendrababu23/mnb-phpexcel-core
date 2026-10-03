<?php
declare(strict_types=1);
namespace Mnb\PHPExcel\Cloud;
final readonly class CloudFile {
 public function __construct(public string $provider, public string $id, public string $name, public ?string $mimeType=null, public ?int $size=null, public ?string $webUrl=null, public ?string $etag=null, public array $raw=[]){}
 public function toArray(): array { return ['provider'=>$this->provider,'id'=>$this->id,'name'=>$this->name,'mime_type'=>$this->mimeType,'size'=>$this->size,'web_url'=>$this->webUrl,'etag'=>$this->etag,'raw'=>$this->raw];}
}