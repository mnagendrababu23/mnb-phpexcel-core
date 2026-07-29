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

use Mnb\PHPExcel\Reader\ReadSession;
use Mnb\PHPExcel\Reader\SheetNamesReaderInterface;
use Mnb\PHPExcel\Support\MnbExcelErrorHandler;
use Mnb\PHPExcel\Support\SheetSelectionException;

final class ErrorHandlerProbeReader implements SheetNamesReaderInterface
{
    public function readSheet(string $path, int|string $sheet = 1, array $options = []): array
    {
        return [];
    }

    public function sheetNames(string $path, array $options = []): array
    {
        return ['ALL_PARAMETERS'];
    }
}

$fixture = tempnam(sys_get_temp_dir(), 'mnb-error-handler-fixture-');
if ($fixture === false) {
    throw new RuntimeException('Unable to create fixture.');
}
file_put_contents($fixture, 'fixture');

try {
    $session = new ReadSession($fixture, new ErrorHandlerProbeReader());
    try {
        $session->sheet('');
        throw new RuntimeException('Expected SheetSelectionException was not thrown.');
    } catch (SheetSelectionException $e) {
        $text = MnbExcelErrorHandler::render($e, true, 'text');
        assert(str_contains($text, 'MNB_SHEET_NAME_INVALID'), $text);
        assert(str_contains($text, 'Worksheet name cannot be empty'), $text);
        assert(!str_contains($text, 'Stack trace'), $text);
        assert(!str_contains($text, 'thrown in'), $text);

        $html = MnbExcelErrorHandler::render($e, true, 'html');
        assert(str_contains($html, '<title>MNB PHPExcel Error</title>'), $html);
        assert(str_contains($html, 'MNB_SHEET_NAME_INVALID'), $html);
        assert(!str_contains($html, 'Stack trace'), $html);

        $json = MnbExcelErrorHandler::render($e, true, 'json');
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        assert(($decoded['code'] ?? null) === 'MNB_SHEET_NAME_INVALID');
        assert(($decoded['recoverable'] ?? null) === true);
    }

    $child = tempnam(sys_get_temp_dir(), 'mnb-error-handler-child-');
    if ($child === false) {
        throw new RuntimeException('Unable to create child script.');
    }
    $childPhp = $child . '.php';
    @rename($child, $childPhp);

    $source = var_export($root . '/src/', true);
    $fixtureLiteral = var_export($fixture, true);
    file_put_contents($childPhp, <<<PHP
<?php

declare(strict_types=1);

\$source = {$source};
spl_autoload_register(static function (string \$class) use (\$source): void {
    \$prefix = 'Mnb\\\\PHPExcel\\\\';
    if (!str_starts_with(\$class, \$prefix)) {
        return;
    }
    \$file = \$source . str_replace('\\\\', '/', substr(\$class, strlen(\$prefix))) . '.php';
    if (is_file(\$file)) {
        require \$file;
    }
});

final class ChildProbeReader implements Mnb\\PHPExcel\\Reader\\SheetNamesReaderInterface
{
    public function readSheet(string \$path, int|string \$sheet = 1, array \$options = []): array { return []; }
    public function sheetNames(string \$path, array \$options = []): array { return ['ALL_PARAMETERS']; }
}

Mnb\\PHPExcel\\Support\\MnbExcelErrorHandler::registerDeveloperMode('text');
(new Mnb\\PHPExcel\\Reader\\ReadSession({$fixtureLiteral}, new ChildProbeReader()))->sheet('');
PHP);

    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($childPhp) . ' 2>&1';
    exec($command, $outputLines, $exitCode);
    $output = implode("\n", $outputLines);

    assert($exitCode === 1, 'Unexpected exit code: ' . $exitCode . "\n" . $output);
    assert(str_contains($output, 'MNB PHPExcel Error [MNB_SHEET_NAME_INVALID]'), $output);
    assert(str_contains($output, 'Worksheet name cannot be empty'), $output);
    assert(!str_contains($output, 'Fatal error'), $output);
    assert(!str_contains($output, 'Stack trace'), $output);
    assert(!str_contains($output, 'thrown in'), $output);

    @unlink($childPhp);
    echo "developer error handler: ok\n";
} finally {
    @unlink($fixture);
}
