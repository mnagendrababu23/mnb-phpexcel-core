<?php
declare(strict_types=1);
namespace Mnb\PHPExcel\Cloud\Auth;
use Mnb\PHPExcel\Cloud\CloudAccount;
interface CredentialProviderInterface {
 public function accessToken(CloudAccount $account): string;
 public function refresh(CloudAccount $account): ?string;
}