<?php
declare(strict_types=1);
namespace Mnb\PHPExcel\Cloud;
final readonly class CloudAccount {
 public function __construct(public string $name, public string $provider, public array $config){}
 public function refreshAccessToken(): ?string { $c=$this->config['credentials']??null; return $c instanceof \Mnb\PHPExcel\Cloud\Auth\CredentialProviderInterface ? $c->refresh($this) : null; }
 public function accessToken(): string {
  $credentials=$this->config['credentials']??null;
  if($credentials instanceof \Mnb\PHPExcel\Cloud\Auth\CredentialProviderInterface) $token=$credentials->accessToken($this);
  else { $token=$this->config['access_token']??null; if(is_callable($token)) $token=$token($this); }
  if(!is_string($token)||$token==='') throw new \RuntimeException("Cloud account {$this->name} has no access token.");
  return $token;
 }
}