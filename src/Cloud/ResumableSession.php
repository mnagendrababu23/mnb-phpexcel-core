<?php
declare(strict_types=1);
namespace Mnb\PHPExcel\Cloud;
final class ResumableSession {
 public function __construct(public readonly string $provider,public readonly string $file,public string $uploadUrl,public int $offset,public readonly int $size,public readonly string $checkpoint){}
 public function save():void{UploadCheckpoint::save($this->checkpoint,['provider'=>$this->provider,'file'=>$this->file,'upload_url'=>$this->uploadUrl,'offset'=>$this->offset,'size'=>$this->size,'updated_at'=>gmdate(DATE_ATOM)]);}
 public function clear():void{UploadCheckpoint::clear($this->checkpoint);}
 public static function restore(string $checkpoint):?self{$s=UploadCheckpoint::load($checkpoint);if(!$s)return null;foreach(['provider','file','upload_url','offset','size'] as $k)if(!array_key_exists($k,$s))return null;return new self((string)$s['provider'],(string)$s['file'],(string)$s['upload_url'],(int)$s['offset'],(int)$s['size'],$checkpoint);}
}