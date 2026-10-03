<?php
declare(strict_types=1);
namespace Mnb\PHPExcel\Cloud\Provider;
use Mnb\PHPExcel\Cloud\{CloudAccount,CloudFile,CloudProviderInterface};
use Mnb\PHPExcel\Cloud\Http\HttpClient;
final class OneDriveProvider implements CloudProviderInterface {
 public function __construct(private ?HttpClient $http=null){$this->http??=new HttpClient();}
 public function name():string{return 'onedrive';}
 private function h(CloudAccount $a):array{return ['Authorization'=>'Bearer '.$a->accessToken()];}
 private function base(CloudAccount $a):string{return rtrim((string)($a->config['graph_base']??'https://graph.microsoft.com/v1.0'),'/').'/me/drive';}
 public function upload(CloudAccount $a,string $path,array $o=[]):CloudFile{
  if(!is_file($path))throw new \InvalidArgumentException("File not found: $path");
  $name=rawurlencode((string)($o['name']??basename($path)));$parent=(string)($o['folder_id']??'root');
  $url=$parent==='root'?$this->base($a)."/root:/$name:/content":$this->base($a).'/items/'.rawurlencode($parent).":/$name:/content";
  $h=$this->h($a);$h['Content-Type']=(string)($o['mime_type']??'application/octet-stream');
  $r=$this->http->request('PUT',$url,$h,file_get_contents($path));return $this->file(json_decode($r['body'],true,512,JSON_THROW_ON_ERROR));
 }
 public function download(CloudAccount $a,string $id,string $dst,array $o=[]):string{
  $r=$this->http->request('GET',$this->base($a).'/items/'.rawurlencode($id).'/content',$this->h($a));
  if(file_put_contents($dst,$r['body'])===false)throw new \RuntimeException("Unable to write $dst");return $dst;
 }
 public function metadata(CloudAccount $a,string $id,array $o=[]):CloudFile{$d=$this->http->json('GET',$this->base($a).'/items/'.rawurlencode($id),$this->h($a));return $this->file($d);}
 public function delete(CloudAccount $a,string $id,array $o=[]):void{$this->http->request('DELETE',$this->base($a).'/items/'.rawurlencode($id),$this->h($a));}
 private function file(array $d):CloudFile{return new CloudFile($this->name(),(string)($d['id']??''),(string)($d['name']??''),$d['file']['mimeType']??null,isset($d['size'])?(int)$d['size']:null,$d['webUrl']??null,$d['eTag']??null,$d);}
}