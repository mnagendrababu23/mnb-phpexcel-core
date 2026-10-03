<?php
declare(strict_types=1);
namespace Mnb\PHPExcel\Cloud;
final class TempFileManager {
 private array $files=[];
 public function __construct(private ?string $directory=null){$this->directory??=sys_get_temp_dir();}
 public function create(string $extension='tmp'):string{$base=tempnam($this->directory,'mnb-cloud-');if($base===false)throw new \RuntimeException('Unable to create temporary file.');$path=$base.'.'.preg_replace('/[^a-z0-9]+/i','',$extension);@unlink($base);$this->files[$path]=true;return $path;}
 public function track(string $path):string{$this->files[$path]=true;return $path;}
 public function release(string $path):void{unset($this->files[$path]);}
 public function remove(string $path):void{unset($this->files[$path]);if(is_file($path))@unlink($path);}
 public function cleanup():void{foreach(array_keys($this->files) as $p)if(is_file($p))@unlink($p);$this->files=[];}
 public function __destruct(){$this->cleanup();}
}