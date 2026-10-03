<?php
declare(strict_types=1);
namespace Mnb\PHPExcel\Cloud\Http;
use Mnb\PHPExcel\Cloud\CloudAccount;
final class AuthenticatedHttpClient {
 public function __construct(private HttpClient $http,private CloudAccount $account){}
 public function request(string $method,string $url,array $headers=[],?string $body=null,int $maxRetries=3,array $accepted=[]):array{
  $headers['Authorization']='Bearer '.$this->account->accessToken();
  try{return $this->http->request($method,$url,$headers,$body,$maxRetries,$accepted);}
  catch(\RuntimeException $e){if(!str_contains($e->getMessage(),'Cloud HTTP 401'))throw $e;$token=$this->account->refreshAccessToken();if(!$token)throw $e;$headers['Authorization']='Bearer '.$token;return $this->http->request($method,$url,$headers,$body,$maxRetries,$accepted);}
 }
 public function json(string $method,string $url,array $headers=[],?array $body=null,int $maxRetries=3,array $accepted=[]):array{
  $headers['Accept']='application/json';if($body!==null)$headers['Content-Type']='application/json';
  $r=$this->request($method,$url,$headers,$body===null?null:json_encode($body,JSON_THROW_ON_ERROR),$maxRetries,$accepted);
  return $r['body']===''?[]:json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);
 }
}