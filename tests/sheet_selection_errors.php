<?php

declare(strict_types=1);

$source = dirname(__DIR__) . '/src/';
spl_autoload_register(static function (string $class) use ($source): void {
    $prefix = 'Mnb\\PHPExcel\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = $source . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use Mnb\PHPExcel\Reader\ReadSession;
use Mnb\PHPExcel\Reader\SheetNamesReaderInterface;
use Mnb\PHPExcel\Support\ErrorCode;
use Mnb\PHPExcel\Support\SheetSelectionException;

final class TestSheetReader implements SheetNamesReaderInterface
{
    public function readSheet(string $path, int|string $sheet = 1, array $options = []): array
    {
        return [['selected', $sheet]];
    }

    public function sheetNames(string $path, array $options = []): array
    {
        return ['ALL_PARAMETERS', 'Archive'];
    }
}

$path = tempnam(sys_get_temp_dir(), 'mnb-sheet-test-');
if ($path === false) {
    throw new RuntimeException('Unable to create test file.');
}
file_put_contents($path, 'fixture');

$session = new ReadSession($path, new TestSheetReader());

$expect = static function (callable $callback, string $code, string $messagePart): SheetSelectionException {
    try {
        $callback();
    } catch (SheetSelectionException $e) {
        assert($e->getErrorCode() === $code, $e->getErrorCode());
        assert(str_contains($e->getMessage(), $messagePart), $e->getMessage());
        assert(str_contains($e->getMessage(), basename(__FILE__)), $e->getMessage());
        assert($e->toErrorArray(true)['recoverable'] === true);
        return $e;
    }

    throw new RuntimeException('Expected SheetSelectionException was not thrown.');
};

$expect(static fn() => $session->sheet(), ErrorCode::SHEET_SELECTION_REQUIRED, 'omit ->sheet() entirely');
$expect(static fn() => $session->sheet(0), ErrorCode::SHEET_INDEX_INVALID, '1-based');
$expect(static fn() => $session->sheet(3), ErrorCode::SHEET_NOT_FOUND, '1="ALL_PARAMETERS"');
$expect(static fn() => $session->sheet(''), ErrorCode::SHEET_NAME_INVALID, 'cannot be empty');
$expect(static fn() => $session->sheet('Sheet1'), ErrorCode::SHEET_NOT_FOUND, 'Available worksheets');

$resolved = $session->sheet('all_parameters');
$property = new ReflectionProperty(ReadSession::class, 'sheetNumber');
assert($property->getValue($resolved) === 'ALL_PARAMETERS');

@unlink($path);
echo "sheet selection errors: ok\n";
