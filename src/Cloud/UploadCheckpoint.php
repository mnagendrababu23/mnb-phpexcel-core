<?php
declare(strict_types=1);
namespace Mnb\PHPExcel\Cloud;
final class UploadCheckpoint {
 public static function save(string $path,array $state):void{$tmp=$path.'.tmp';file_put_contents($tmp,json_encode($state,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),LOCK_EX);if(!@rename($tmp,$path)){@unlink($tmp);throw new \RuntimeException("Unable to save checkpoint $path");}}
 public static function load(string $path):array{if(!is_file($path))return [];$v=json_decode((string)file_get_contents($path),true);return is_array($v)?$v:[];}
 public static function clear(string $path):void{if(is_file($path))@unlink($path);}
}