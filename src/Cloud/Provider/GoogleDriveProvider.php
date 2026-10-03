<?php
declare(strict_types=1);
namespace Mnb\PHPExcel\Cloud\Provider;
use Mnb\PHPExcel\Cloud\{CloudAccount,CloudFile,CloudProviderInterface,ResumableCloudProviderInterface};
use Mnb\PHPExcel\Cloud\Http\HttpClient;
final class GoogleDriveProvider implements ResumableCloudProviderInterface {
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
 public function uploadResumable(CloudAccount $a,string $path,array $o=[]):CloudFile{
  $size=filesize($path);if($size===false)throw new \InvalidArgumentException("File not found: $path");$name=(string)($o['name']??basename($path));$mime=(string)($o['mime_type']??'application/octet-stream');
  $meta=['name'=>$name];if(!empty($o['folder_id']))$meta['parents']=[(string)$o['folder_id']];
  $h=$this->h($a);$h['Content-Type']='application/json; charset=UTF-8';$h['X-Upload-Content-Type']=$mime;$h['X-Upload-Content-Length']=(string)$size;
  $r=$this->http->request('POST','https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id,name,mimeType,size,webViewLink,md5Checksum',$h,json_encode($meta,JSON_THROW_ON_ERROR));
  $uploadUrl=$r['headers']['location']??null;if(!$uploadUrl)throw new \RuntimeException('Google Drive did not return a resumable upload URL.');$checkpoint=$o['checkpoint_file']??null;$session=$checkpoint?new \Mnb\PHPExcel\Cloud\ResumableSession($this->name(),$path,$uploadUrl,0,$size,(string)$checkpoint):null;$session?->save();
  $chunk=max(262144,(int)($o['chunk_size']??4194304));$chunk=(int)(ceil($chunk/262144)*262144);$fh=fopen($path,'rb');$offset=0;$last=[];
  while(!feof($fh)&&$offset<$size){$data=fread($fh,min($chunk,$size-$offset));$end=$offset+strlen($data)-1;$hh=['Content-Length'=>(string)strlen($data),'Content-Type'=>$mime,'Content-Range'=>"bytes $offset-$end/$size"];$rr=$this->http->request('PUT',$uploadUrl,$hh,$data,(int)($o['max_retries']??0),[308]);if($rr['body']!=='')$last=json_decode($rr['body'],true)?:$last;if($rr['status']===308){$range=$rr['headers']['range']??'';$offset=preg_match('/bytes=0-(\d+)/',$range,$m)?((int)$m[1]+1):$end+1;}else{$offset=$end+1;}if($session){$session->offset=$offset;$session->save();}if(isset($o['progress'])&&is_callable($o['progress']))($o['progress'])($offset,$size);}
  fclose($fh);$session?->clear();return $this->file($last);
 }

 public function download(CloudAccount $a,string $id,string $dst,array $o=[]):string{
  $this->http->downloadTo('https://www.googleapis.com/drive/v3/files/'.rawurlencode($id).'?alt=media',$dst,$this->h($a),$o);return $dst;
 }
 public function export(CloudAccount $a,string $id,string $dst,string $mime='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',array $o=[]):string{
  $url='https://www.googleapis.com/drive/v3/files/'.rawurlencode($id).'/export?mimeType='.rawurlencode($mime);
  $this->http->downloadTo($url,$dst,$this->h($a),$o);return $dst;
 }

 public function metadata(CloudAccount $a,string $id,array $o=[]):CloudFile{
  $d=$this->http->json('GET','https://www.googleapis.com/drive/v3/files/'.rawurlencode($id).'?fields=id,name,mimeType,size,webViewLink,md5Checksum',$this->h($a));return $this->file($d);
 }
 public function delete(CloudAccount $a,string $id,array $o=[]):void{$this->http->request('DELETE','https://www.googleapis.com/drive/v3/files/'.rawurlencode($id),$this->h($a));}
 private function file(array $d):CloudFile{return new CloudFile($this->name(),(string)($d['id']??''),(string)($d['name']??''),$d['mimeType']??null,isset($d['size'])?(int)$d['size']:null,$d['webViewLink']??null,null,$d);}
}