<?php
declare(strict_types=1);
namespace Mnb\PHPExcel\Cloud;
interface CloudProviderInterface {
 public function name(): string;
 public function upload(CloudAccount $account,string $localPath,array $options=[]): CloudFile;
 public function download(CloudAccount $account,string $remoteId,string $destination,array $options=[]): string;
 public function metadata(CloudAccount $account,string $remoteId,array $options=[]): CloudFile;
 public function delete(CloudAccount $account,string $remoteId,array $options=[]): void;
}