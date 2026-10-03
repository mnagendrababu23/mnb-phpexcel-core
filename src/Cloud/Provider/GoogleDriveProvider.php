<?php
declare(strict_types=1);
namespace Mnb\PHPExcel\Cloud\Provider;
use Mnb\PHPExcel\Cloud\{CloudAccount,CloudFile,CloudProviderInterface};
use Mnb\PHPExcel\Cloud\Http\HttpClient;
final class GoogleDriveProvider implements CloudProviderInterface {
 public function __construct(private ?HttpClient $http=null){$this->http??=new HttpClient();}
 public function name(): string{return 'google-drive';}
 private function h(CloudAccount $a):array{return ['Authorization'=>'Bearer '.$a->accessToken()];}
 public function upload(CloudAccount $a,string $path,array $o=[]):CloudFile{
  if(!is_file($path))throw new \InvalidArgumentException("File not found: $path");
  $name=(string)($o['name']??basename($path));$mime=(string)($o['mime_type']??'application/octet-stream');
  $meta=['name'=>$name];if(!empty($o['folder_id']))$meta['parents']=[(string)$o['folder_id']];
  $boundary='mnb'.bin2hex(random_bytes(12));
  $body="--$boundary\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n".json_encode($meta,JSON_THROW_ON_ERROR)."\r\n--$boundary\r\nContent-Type: $mime\r\n\r\n".file_get_contents($path)."\r\n--$boundary--";
  $h=$this->h($a);$h['Content-Type']="multipart/related; boundary=$boundary";
  return $this->multipart($a,$body,$boundary);
 }
 private function multipart(CloudAccount $a,string $body,string $boundary):CloudFile{
  $h=$this->h($a);$h['Content-Type']="multipart/related; boundary=$boundary";
  $r=$this->http->request('POST','https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,mimeType,size,webViewLink,md5Checksum',$h,$body);
  $d=json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);return $this->file($d);
 }
 public function download(CloudAccount $a,string $id,string $dst,array $o=[]):string{
  $r=$this->http->request('GET','https://www.googleapis.com/drive/v3/files/'.rawurlencode($id).'?alt=media',$this->h($a));
  if(file_put_contents($dst,$r['body'])===false)throw new \RuntimeException("Unable to write $dst");return $dst;
 }
 public function metadata(CloudAccount $a,string $id,array $o=[]):CloudFile{
  $d=$this->http->json('GET','https://www.googleapis.com/drive/v3/files/'.rawurlencode($id).'?fields=id,name,mimeType,size,webViewLink,md5Checksum',$this->h($a));return $this->file($d);
 }
 public function delete(CloudAccount $a,string $id,array $o=[]):void{$this->http->request('DELETE','https://www.googleapis.com/drive/v3/files/'.rawurlencode($id),$this->h($a));}
 private function file(array $d):CloudFile{return new CloudFile($this->name(),(string)($d['id']??''),(string)($d['name']??''),$d['mimeType']??null,isset($d['size'])?(int)$d['size']:null,$d['webViewLink']??null,null,$d);}
}