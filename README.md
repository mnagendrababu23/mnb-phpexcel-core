# MNB PHPExcel Core

Shared contracts, workbook data models, typed reader sessions, projection, validation, progress reporting, capability interfaces, and the modular reader registry.

```bash
composer require mnb/mnb-phpexcel-core:^2.0
```

Core does not install any spreadsheet format. Install one or more format modules, then use the format-specific facade or `SpreadsheetManager`.

```php
use Mnb\PHPExcel\SpreadsheetManager;

$excel = SpreadsheetManager::create();
$rows = $excel->read('data.csv')->withHeaderRow()->toArray();
```

Optional session conversions fail with an actionable package-install message when the corresponding JSON, XML, or database module is absent. Rich workbook operations are exposed through capability interfaces rather than concrete XLSX dependencies.

## Structured developer errors

All worksheet-selection failures use `Mnb\PHPExcel\Support\SheetSelectionException`, a subtype of `MnbExcelException`. It exposes a stable error code, safe message, debug context, workbook path, available worksheet names, and the application caller file/line.

```php
try {
    $session->sheet('Unknown');
} catch (Mnb\PHPExcel\Support\MnbExcelException $e) {
    print_r($e->toErrorArray(debug: true));
}
```


## Worksheet discovery and empty-data checks

Reader sessions expose non-throwing discovery helpers and explicit row assertions:

```php
$session = $excel->read('report.xlsx');

$session->hasSheet('Data');          // bool, case-insensitive name check
$session->sheetExists(2);            // alias; worksheet numbers are 1-based
$session->sheetIfExists('Optional'); // ReadSession|null

$session->activeSheetInfo();         // ['index' => 2, 'name' => 'Data']
$session->activeSheetName();         // Data
$session->activeSheetIndex();        // 2
$session->activeSheet();             // cloned session selecting the active worksheet

$selected = $session->activeSheet()->withHeaderRow(1);
$selected->hasRows();                // true when normalized data rows remain
$selected->isEmpty();                // inverse of hasRows()
$selected->countRows();              // exact normalized data-row count
$selected->assertHasRows();          // fluent session or EmptyWorksheetException
```

`hasRows()` and `isEmpty()` evaluate rows after the configured header, range, skip, filtering, projection, and empty-row options. A sheet containing only a header row is therefore empty from the data-processing perspective.
