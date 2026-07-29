<?php

declare(strict_types=1);

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Mnb\\PHPExcel\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use Mnb\PHPExcel\Reader\ActiveSheetReaderInterface;
use Mnb\PHPExcel\Reader\IterableReaderInterface;
use Mnb\PHPExcel\Reader\ReadSession;
use Mnb\PHPExcel\Reader\SheetNamesReaderInterface;
use Mnb\PHPExcel\Support\EmptyWorksheetException;
use Mnb\PHPExcel\Support\ErrorCode;

final class WorkbookProbeReader implements IterableReaderInterface, SheetNamesReaderInterface, ActiveSheetReaderInterface
{
    /** @return list<string> */
    public function sheetNames(string $path, array $options = []): array
    {
        return ['Cover', 'Data', 'Empty'];
    }

    /** @return array{index:int,name:string} */
    public function activeSheet(string $path, array $options = []): array
    {
        return ['index' => 2, 'name' => 'Data'];
    }

    public function readSheet(string $path, int|string $sheet = 1, array $options = []): array
    {
        return iterator_to_array($this->iterateSheet($path, $sheet, $options), false);
    }

    public function iterateSheet(string $path, int|string $sheet = 1, array $options = []): iterable
    {
        $name = is_int($sheet) || ctype_digit((string) $sheet)
            ? $this->sheetNames($path)[(int) $sheet - 1]
            : (string) $sheet;

        if (strcasecmp($name, 'Data') === 0) {
            yield 0 => ['id', 'name'];
            yield 1 => [1, 'Ada'];
            yield 2 => [2, 'Linus'];
        }
    }
}

$path = tempnam(sys_get_temp_dir(), 'mnb-sheet-probe-');
if ($path === false) {
    throw new RuntimeException('Unable to create temporary file.');
}
file_put_contents($path, 'fixture');

try {
    $session = new ReadSession($path, new WorkbookProbeReader());

    assert($session->hasSheet(1));
    assert($session->hasSheet('data'));
    assert($session->sheetExists('Empty'));
    assert(!$session->hasSheet(0));
    assert(!$session->hasSheet('Missing'));
    assert($session->sheetIfExists('Missing') === null);
    assert($session->sheetIfExists('Data') instanceof ReadSession);

    assert($session->activeSheetIndex() === 2);
    assert($session->activeSheetName() === 'Data');
    assert($session->activeSheetInfo() === ['index' => 2, 'name' => 'Data']);

    $active = $session->activeSheet()->withHeaderRow(1);
    assert($active->hasRows());
    assert(!$active->isEmpty());
    assert($active->countRows() === 2);
    assert($active->assertHasRows() === $active);
    assert($active->requireRows() === $active);

    $empty = $session->sheet('Empty');
    assert(!$empty->hasRows());
    assert($empty->isEmpty());
    assert($empty->countRows() === 0);

    try {
        $empty->assertHasRows();
        throw new RuntimeException('Expected EmptyWorksheetException was not thrown.');
    } catch (EmptyWorksheetException $e) {
        assert($e->getErrorCode() === ErrorCode::SHEET_EMPTY);
        assert(str_contains($e->getMessage(), 'zero readable data rows'));
        assert(($e->context()['selected_sheet'] ?? null) === 'Empty');
    }

    echo "sheet discovery and empty-row checks passed\n";
} finally {
    @unlink($path);
}
