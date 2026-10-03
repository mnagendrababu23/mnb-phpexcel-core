<?php
declare(strict_types=1);
namespace Mnb\PHPExcel\Cloud\Http;
final class HttpClient {
 public function request(string $method,string $url,array $headers=[],?string $body=null,int $maxRetries=3,array $acceptedStatuses=[]):array{
  $attempt=0;
  do{
   $r=$this->once($method,$url,$headers,$body);
   if($this->accepted($r['status'],$acceptedStatuses))return $r+['attempts'=>$attempt+1];
   if(!$this->retryable($r['status'])||$attempt >= $maxRetries)$this->fail($method,$url,$r);
   $this->sleepFor($r['headers'],$attempt++);
  }while(true);
 }
 public function uploadStream(string $method,string $url,$stream,int $length,array $headers=[],int $maxRetries=3,array $acceptedStatuses=[]):array{
  if(!is_resource($stream))throw new \InvalidArgumentException('Upload stream must be a resource.');
  $body=stream_get_contents($stream,$length);
  if($body===false||strlen($body)!==$length)throw new \RuntimeException('Unable to read requested upload chunk.');
  return $this->request($method,$url,$headers,$body,$maxRetries,$acceptedStatuses);
 }
 public function downloadTo(string $url,string $destination,array $headers=[],array $options=[]):array{
  $resume=(bool)($options['resume']??true);$progress=$options['progress']??null;$maxRetries=(int)($options['max_retries']??3);
  $offset=$resume&&is_file($destination)?(filesize($destination)?:0):0;$attempt=0;
  do{
   $h=$headers;if($offset>0)$h['Range']="bytes=$offset-";
   $headerLines=[];foreach($h as $k=>$v)$headerLines[]="$k: $v";
   $ctx=stream_context_create(['http'=>['method'=>'GET','header'=>implode("\r\n",$headerLines),'ignore_errors'=>true,'timeout'=>(int)($options['timeout']??60)]]);
   $in=@fopen($url,'rb',false,$ctx);$rh=$http_response_header??[];$status=$this->status($rh);$map=$this->headers($rh);
   if($in!==false&&($status===200||$status===206)){
    if($offset>0&&$status===200){$offset=0;$mode='wb';}else{$mode=$offset>0?'ab':'wb';}
    $out=fopen($destination,$mode);if($out===false){fclose($in);throw new \RuntimeException("Unable to write $destination");}
    $copied=0;while(!feof($in)){$buf=fread($in,1048576);if($buf===false)break;if($buf==='')continue;$n=fwrite($out,$buf);if($n===false)break;$copied+=$n;if(is_callable($progress))$progress($offset+$copied,null);}
    fclose($out);fclose($in);return ['status'=>$status,'headers'=>$map,'bytes'=>$offset+$copied,'attempts'=>$attempt+1];
   }
   if(is_resource($in))fclose($in);
   if(!$this->retryable($status)||$attempt >= $maxRetries)throw new \RuntimeException("Cloud HTTP $status downloading $url");
   $offset=is_file($destination)?(filesize($destination)?:0):0;$this->sleepFor($map,$attempt++);
  }while(true);
 }
 public function json(string $method,string $url,array $headers=[],?array $body=null,int $maxRetries=3,array $acceptedStatuses=[]):array{
  $headers['Accept']='application/json';if($body!==null)$headers['Content-Type']='application/json';
  $r=$this->request($method,$url,$headers,$body===null?null:json_encode($body,JSON_THROW_ON_ERROR),$maxRetries,$acceptedStatuses);
  return $r['body']===''?[]:json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);
 }
 private function once(string $method,string $url,array $headers,?string $body):array{
  $lines=[];foreach($headers as $k=>$v)$lines[]="$k: $v";$opts=['http'=>['method'=>$method,'header'=>implode("\r\n",$lines),'ignore_errors'=>true,'timeout'=>60]];if($body!==null)$opts['http']['content']=$body;
  $data=@file_get_contents($url,false,stream_context_create($opts));$rh=$http_response_header??[];return ['status'=>$this->status($rh),'headers'=>$this->headers($rh),'body'=>$data===false?'':$data];
 }
 private function accepted(int $status,array $extra):bool{return ($status>=200&&$status<300)||in_array($status,$extra,true);}
 private function retryable(int $status):bool{return $status===0||$status===408||$status===429||$status>=500;}
 private function status(array $h):int{if(isset($h[0])&&preg_match('/\s(\d{3})\s/',$h[0],$m))return (int)$m[1];return 0;}
 private function headers(array $h):array{$m=[];foreach($h as $line)if(str_contains($line,':')){[$k,$v]=explode(':',$line,2);$m[strtolower(trim($k))]=trim($v);}return $m;}
 private function sleepFor(array $headers,int $attempt):void{$retry=$headers['retry-after']??null;if(is_numeric($retry))$us=(int)$retry*1000000;else $us=min(5000000,100000*(2**$attempt));$us+=random_int(0,max(1,(int)($us*.2)));usleep($us);}
 private function fail(string $method,string $url,array $r):never{throw new \RuntimeException("Cloud HTTP {$r['status']} for $method $url: ".substr($r['body'],0,1000));}
}