<?php
declare(strict_types=1);
namespace Mnb\PHPExcel\Cloud;
final class CloudTelemetry {
 private array $c=['requests'=>0,'retries'=>0,'uploaded_bytes'=>0,'downloaded_bytes'=>0,'rate_limits'=>0,'auth_refreshes'=>0,'conflicts'=>0];
 public function add(string $key,int $n=1):void{$this->c[$key]=($this->c[$key]??0)+$n;}
 public function snapshot():array{return $this->c;}
 public function reset():void{foreach($this->c as $k=>$v)$this->c[$k]=0;}
}