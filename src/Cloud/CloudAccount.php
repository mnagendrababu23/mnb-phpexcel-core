<?php
declare(strict_types=1);
namespace Mnb\PHPExcel\Cloud;
final readonly class CloudAccount {
 public function __construct(public string $name, public string $provider, public array $config){}
 public function accessToken(): string {
  $token=$this->config['access_token']??null;
  if(is_callable($token)) $token=$token($this);
  if(!is_string($token)||$token==='') throw new \RuntimeException("Cloud account {$this->name} has no access token.");
  return $token;
 }
}