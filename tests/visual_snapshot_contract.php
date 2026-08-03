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

use Mnb\PHPExcel\Core\CellValue;
use Mnb\PHPExcel\Core\StyleNormalizer;
use Mnb\PHPExcel\Snapshot\VisualSnapshot;

$style = StyleNormalizer::normalize([
    'number_format' => 'yyyy-mm-dd',
    'fill' => ['color' => '#1F4E78'],
    'borders' => ['all' => ['style' => 'thin', 'color' => '#808080']],
]);
assert(($style['format'] ?? null) === 'yyyy-mm-dd');
assert(($style['fill']['foreground']['rgb'] ?? null) === 'FF1F4E78');
foreach (['left', 'right', 'top', 'bottom'] as $side) {
    assert(($style['border'][$side]['style'] ?? null) === 'thin');
    assert(($style['border'][$side]['color']['rgb'] ?? null) === 'FF808080');
}

$snapshot = [
    'schema' => VisualSnapshot::SCHEMA,
    'schema_version' => VisualSnapshot::VERSION,
    'format' => 'xlsx',
    'workbook' => [
        'active_sheet' => 1,
        'date1904' => false,
        'metadata' => [
            'document' => ['title' => 'Snapshot', 'creator' => 'MNB'],
            'revision' => ['last_saved_by' => 'QA'],
            'application' => ['application' => 'Excel', 'company' => 'MNB'],
            'custom_properties' => [
                'items' => [
                    ['name' => 'Project ID', 'type' => 'string', 'value' => 'P-1'],
                ],
            ],
        ],
    ],
    'styles' => ['s1' => $style],
    'sheets' => [[
        'name' => 'Sheet1',
        'state' => 'visible',
        'dimension' => 'A1:B2',
        'cells' => [
            'A1' => ['type' => 'text', 'value' => 'Date', 'style_id' => 's1'],
            'B2' => ['type' => 'date', 'value' => '2026-07-01', 'format' => 'yyyy-mm-dd'],
        ],
        'layout' => [
            'column_widths' => ['A' => 18],
            'row_heights' => [1 => 24],
            'freeze_panes' => ['rows' => 1, 'columns' => 0, 'top_left_cell' => 'A2'],
            'auto_filter_range' => 'A1:B2',
        ],
    ]],
];

$json = VisualSnapshot::toJson($snapshot);
$decoded = VisualSnapshot::fromJson($json);
$workbook = VisualSnapshot::toWorkbookData($decoded);
assert($workbook->metadata['title'] === 'Snapshot');
assert($workbook->metadata['creator'] === 'MNB');
assert($workbook->metadata['last_modified_by'] === 'QA');
assert($workbook->metadata['company'] === 'MNB');
assert(($workbook->metadata['custom_properties']['Project ID']['value'] ?? null) === 'P-1');
assert($workbook->sheets[0]->rows[1][1] instanceof CellValue);
assert($workbook->sheets[0]->rows[1][1]->type() === CellValue::TYPE_DATE);
assert($workbook->sheets[0]->cellStyles['A1']['border']['left']['style'] === 'thin');
assert($workbook->sheets[0]->freezeRows === 1);
assert($workbook->sheets[0]->autoFilterRange === 'A1:B2');

echo "visual_snapshot_contract passed\n";
