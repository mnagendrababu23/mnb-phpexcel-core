<?php
declare(strict_types=1);
namespace Mnb\PHPExcel\Cloud;
final class RemoteFile {
 private ?string $localPath=null;
 public function __construct(private CloudManager $manager,public readonly CloudFile $file,private array $options=[]){}
 public function localPath():string{if($this->localPath!==null)return $this->localPath;$ext=pathinfo($this->file->name,PATHINFO_EXTENSION)?:'tmp';$this->localPath=$this->manager->temporaryFile($ext);$this->manager->download($this->file->id,$this->localPath,$this->options);return $this->localPath;}
 public function close():void{if($this->localPath!==null){$this->manager->removeTemporaryFile($this->localPath);$this->localPath=null;}}
 public function __destruct(){$this->close();}
}