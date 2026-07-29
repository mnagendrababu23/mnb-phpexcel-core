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
