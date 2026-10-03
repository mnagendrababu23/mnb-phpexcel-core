<?php
declare(strict_types=1);
namespace Mnb\PHPExcel\Cloud\Http;
final class HttpClient {
 public function request(string $method,string $url,array $headers=[],?string $body=null): array {
  $headerLines=[]; foreach($headers as $k=>$v)$headerLines[]=$k.': '.$v;
  $opts=['http'=>['method'=>$method,'header'=>implode("\r\n",$headerLines),'ignore_errors'=>true,'timeout'=>60]];
  if($body!==null)$opts['http']['content']=$body;
  $ctx=stream_context_create($opts); $data=@file_get_contents($url,false,$ctx);
  $responseHeaders=$http_response_header??[]; $status=0;
  if(isset($responseHeaders[0])&&preg_match('/\s(\d{3})\s/',$responseHeaders[0],$m))$status=(int)$m[1];
  $map=[];foreach($responseHeaders as $line){if(str_contains($line,':')){[$k,$v]=explode(':',$line,2);$map[strtolower(trim($k))]=trim($v);}}
  if($data===false)$data='';
  if($status<200||$status>=300)throw new \RuntimeException("Cloud HTTP $status for $method $url: ".substr($data,0,1000));
  return ['status'=>$status,'headers'=>$map,'body'=>$data];
 }
 public function json(string $method,string $url,array $headers=[],?array $body=null): array {
  $headers['Accept']='application/json'; if($body!==null)$headers['Content-Type']='application/json';
  $r=$this->request($method,$url,$headers,$body===null?null:json_encode($body,JSON_THROW_ON_ERROR));
  return $r['body']===''?[]:json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);
 }
}