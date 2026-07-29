# Changelog

## 2.0.3
- Added `hasSheet()`, `sheetExists()`, and nullable `sheetIfExists()` worksheet discovery helpers.
- Added `activeSheetInfo()`, `activeSheetName()`, `activeSheetIndex()`, `activeSheet()`, and `useActiveSheet()`.
- Added `hasRows()`, `isEmpty()`, `assertHasRows()`, and `requireRows()` for normalized zero-row handling.
- Added `EmptyWorksheetException` and stable `MNB_SHEET_EMPTY` error code.
- Broadened `first()` and `countRows()` to accept `ReaderOptions` as well as arrays.


## 2.0.2
- Added developer-friendly worksheet selection errors with stable error codes, workbook context, valid sheet names, and the caller file/line.
- `sheet()` now accepts an omitted argument only to throw a library exception instead of a PHP `ArgumentCountError`; omit the method entirely to use the first worksheet.
- Worksheet indexes are explicitly 1-based and invalid names/indexes fail before row iteration.

## 2.0.1
- Fixed native `XMLReader` initialization so properties are not read before the first XML node is available.

## 2.0.0
- Hardened the package-local ZIP reader so a missing zlib inflater fails safely instead of causing an undefined-function fatal error.

- Coordinated MNB PHPExcel v2 release.
- Internal MNB dependencies aligned to `^2.0`.
- Package boundaries validated for independent installation.
