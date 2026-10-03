<?php
declare(strict_types=1);
namespace Mnb\PHPExcel\Cloud;
use Mnb\PHPExcel\Cloud\Provider\{GoogleDriveProvider,GoogleSheetsProvider,OneDriveProvider,LocalProvider};
final class CloudManager {
 /** @var array<string,CloudAccount> */ private array $accounts=[];
 /** @var array<string,CloudProviderInterface> */ private array $providers=[];
 private GoogleSheetsProvider $sheets;
 private TempFileManager $temps;
 public function __construct(?TempFileManager $temps=null){
  $this->temps=$temps??new TempFileManager();
  $this->providers['local']=new LocalProvider();$this->providers['google-drive']=new GoogleDriveProvider();$this->providers['onedrive']=new OneDriveProvider();$this->sheets=new GoogleSheetsProvider();
 }
 public function registerProvider(CloudProviderInterface $provider): self { $this->providers[$provider->name()]=$provider; return $this; }
 public function account(string $name,array $config=[]):self{
  if($config!==[]){$provider=(string)($config['provider']??'');if(!isset($this->providers[$provider]))throw new \InvalidArgumentException("Unknown cloud provider: $provider");$this->accounts[$name]=new CloudAccount($name,$provider,$config);}
  elseif(!isset($this->accounts[$name]))throw new \InvalidArgumentException("Unknown cloud account: $name");
  $c=clone $this;$c->accounts=$this->accounts;$c->selected=$name;return $c;
 }
 private ?string $selected=null;
 private function a(?string $name=null):CloudAccount{$name??=$this->selected;if($name===null||!isset($this->accounts[$name]))throw new \LogicException('Select/configure a cloud account first.');return $this->accounts[$name];}
 public function temporaryFile(string $extension='tmp'):string{return $this->temps->create($extension);}
 public function removeTemporaryFile(string $path):void{$this->temps->remove($path);}
 public function cleanupTemporaryFiles():void{$this->temps->cleanup();}
 public function open(string $remoteId,array $options=[]):RemoteFile{$a=$this->a($options['account']??null);$file=$this->providers[$a->provider]->metadata($a,$remoteId,$options);return new RemoteFile($this,$file,$options);}
  public function upload(string $localPath,array $options=[]):CloudFile{$a=$this->a($options['account']??null);$p=$this->providers[$a->provider];$size=is_file($localPath)?(filesize($localPath)?:0):0;$threshold=(int)($options['resumable_threshold']??8388608);if($size>$threshold&&$p instanceof ResumableCloudProviderInterface)return $p->uploadResumable($a,$localPath,$options);return $p->upload($a,$localPath,$options);}
 public function uploadResumable(string $localPath,array $options=[]):CloudFile{$a=$this->a($options['account']??null);$p=$this->providers[$a->provider];if(!$p instanceof ResumableCloudProviderInterface)throw new \LogicException("Provider {$a->provider} does not support resumable upload.");return $p->uploadResumable($a,$localPath,$options);}
  public function download(string $remoteId,string $destination,array $options=[]):string{$a=$this->a($options['account']??null);return $this->providers[$a->provider]->download($a,$remoteId,$destination,$options);}
 public function file(string $remoteId,array $options=[]):CloudFile{$a=$this->a($options['account']??null);return $this->providers[$a->provider]->metadata($a,$remoteId,$options);}
 public function delete(string $remoteId,array $options=[]):void{$a=$this->a($options['account']??null);$this->providers[$a->provider]->delete($a,$remoteId,$options);}
 public function createGoogleSheet(string $title,array $sheets=[],array $options=[]):CloudFile{$a=$this->a($options['account']??null);if($a->provider!=='google-drive')throw new \LogicException('Google Sheets requires a google-drive account.');return $this->sheets->create($a,$title,$sheets);}
 public function readGoogleSheet(string $id,string $range='Sheet1!A:ZZ',array $options=[]):array{$a=$this->a($options['account']??null);return $this->sheets->readRows($a,$id,$range);}
}