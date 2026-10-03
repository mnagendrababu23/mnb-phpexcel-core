<?php
declare(strict_types=1);
namespace Mnb\PHPExcel\Cloud;
trait CloudAccess {
 private static ?CloudManager $cloudManager = null;
 public static function cloud(): CloudManager { return self::$cloudManager ??= new CloudManager(); }
 public static function setCloudManager(CloudManager $manager): void { self::$cloudManager=$manager; }
}