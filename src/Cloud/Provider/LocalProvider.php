<?php
declare(strict_types=1);
namespace Mnb\PHPExcel\Cloud\Provider;
use Mnb\PHPExcel\Cloud\{CloudAccount,CloudFile,CloudProviderInterface};
final class LocalProvider implements CloudProviderInterface {
 public function name():string{return 'local';}
 public function upload(CloudAccount $a,string $src,array $o=[]):CloudFile{$dst=(string)($o['path']??$o['destination']??'');if($dst==='')throw new \InvalidArgumentException('Local destination path required.');if(!@copy($src,$dst))throw new \RuntimeException("Unable to copy to $dst");return $this->metadata($a,$dst);}
 public function download(CloudAccount $a,string $id,string $dst,array $o=[]):string{if(!@copy($id,$dst))throw new \RuntimeException("Unable to copy to $dst");return $dst;}
 public function metadata(CloudAccount $a,string $id,array $o=[]):CloudFile{if(!is_file($id))throw new \InvalidArgumentException("File not found: $id");return new CloudFile('local',$id,basename($id),null,filesize($id)?:0,null,null,['path'=>$id]);}
 public function delete(CloudAccount $a,string $id,array $o=[]):void{if(is_file($id)&&!@unlink($id))throw new \RuntimeException("Unable to delete $id");}
}