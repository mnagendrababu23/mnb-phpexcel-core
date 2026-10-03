<?php
declare(strict_types=1);
namespace Mnb\PHPExcel\Cloud\Provider;
use Mnb\PHPExcel\Cloud\{CloudAccount,CloudFile,CloudProviderInterface,ResumableCloudProviderInterface};
use Mnb\PHPExcel\Cloud\Http\HttpClient;
final class OneDriveProvider implements ResumableCloudProviderInterface {
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
 public function uploadResumable(CloudAccount $a,string $path,array $o=[]):CloudFile{
  $size=filesize($path);if($size===false)throw new \InvalidArgumentException("File not found: $path");
  $name=(string)($o['name']??basename($path));$parent=(string)($o['folder_id']??'root');
  $url=$parent==='root'?$this->base($a).'/root:/'.rawurlencode($name).':/createUploadSession':$this->base($a).'/items/'.rawurlencode($parent).':/'.rawurlencode($name).':/createUploadSession';
  $session=$this->http->json('POST',$url,$this->h($a),['item'=>['@microsoft.graph.conflictBehavior'=>$o['conflict_behavior']??'replace','name'=>$name]]);
  $uploadUrl=(string)$session['uploadUrl'];$chunk=max(327680,(int)($o['chunk_size']??3276800));$chunk=(int)(ceil($chunk/327680)*327680);
  $fh=fopen($path,'rb');$offset=0;$last=[];
  while(!feof($fh)&&$offset<$size){$data=fread($fh,min($chunk,$size-$offset));$end=$offset+strlen($data)-1;$h=['Content-Length'=>(string)strlen($data),'Content-Range'=>"bytes $offset-$end/$size"];$r=$this->http->request('PUT',$uploadUrl,$h,$data,(int)($o['max_retries']??0));$reply=$r['body']===''?[]:(json_decode($r['body'],true)?:[]);$last=$reply?:$last;if($r['status']===202&&isset($reply['nextExpectedRanges'][0])&&preg_match('/^(\d+)-/',$reply['nextExpectedRanges'][0],$m))$offset=(int)$m[1];else $offset=$end+1;if(isset($o['progress'])&&is_callable($o['progress']))($o['progress'])($offset,$size);}
  fclose($fh);return $this->file($last);
 }

 public function download(CloudAccount $a,string $id,string $dst,array $o=[]):string{
  $this->http->downloadTo($this->base($a).'/items/'.rawurlencode($id).'/content',$dst,$this->h($a),$o);return $dst;
 }
 public function metadata(CloudAccount $a,string $id,array $o=[]):CloudFile{$d=$this->http->json('GET',$this->base($a).'/items/'.rawurlencode($id),$this->h($a));return $this->file($d);}
 public function delete(CloudAccount $a,string $id,array $o=[]):void{$this->http->request('DELETE',$this->base($a).'/items/'.rawurlencode($id),$this->h($a));}
 private function file(array $d):CloudFile{return new CloudFile($this->name(),(string)($d['id']??''),(string)($d['name']??''),$d['file']['mimeType']??null,isset($d['size'])?(int)$d['size']:null,$d['webUrl']??null,$d['eTag']??null,$d);}
}