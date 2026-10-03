<?php
declare(strict_types=1);
namespace Mnb\PHPExcel\Cloud;
final class RemoteFile {
 private ?string $localPath=null;
 public function __construct(private CloudManager $manager,public readonly CloudFile $file,private array $options=[]){}
 public function localPath():string{
  if($this->localPath!==null)return $this->localPath;
  $native=$this->file->mimeType==='application/vnd.google-apps.spreadsheet';
  $ext=$native?'xlsx':(pathinfo($this->file->name,PATHINFO_EXTENSION)?:'tmp');
  $this->localPath=$this->manager->temporaryFile($ext);
  if($native)$this->manager->exportGoogleFile($this->file->id,$this->localPath,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',$this->options);
  else $this->manager->download($this->file->id,$this->localPath,$this->options);
  return $this->localPath;
 }
 public function rows(array $options=[]):iterable{
  if(!class_exists(\Mnb\PHPExcel\MnbExcel::class)||!method_exists(\Mnb\PHPExcel\MnbExcel::class,'open'))throw new \LogicException('rows() requires the application or monolith MnbExcel facade.');
  return \Mnb\PHPExcel\MnbExcel::open($this->localPath(),$options)->rows();
 }
 public function meta(array $options=[]):mixed{
  if(!class_exists(\Mnb\PHPExcel\MnbExcel::class)||!method_exists(\Mnb\PHPExcel\MnbExcel::class,'meta'))throw new \LogicException('meta() requires the application or monolith MnbExcel facade.');
  return \Mnb\PHPExcel\MnbExcel::meta($this->localPath(),$options);
 }
 public function close():void{if($this->localPath!==null){$this->manager->removeTemporaryFile($this->localPath);$this->localPath=null;}}
 public function __destruct(){$this->close();}
}