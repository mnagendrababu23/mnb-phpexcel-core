<?php
declare(strict_types=1);
namespace Mnb\PHPExcel\Cloud;
interface ResumableCloudProviderInterface extends CloudProviderInterface {
 public function uploadResumable(CloudAccount $account,string $localPath,array $options=[]): CloudFile;
}