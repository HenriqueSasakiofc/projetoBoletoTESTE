<?php
require 'vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable('.');
$dotenv->load();
\Config\Database::connect();

// Find xlsx files to test
$searchPaths = ['.', 'uploads', 'tests', 'database', 'storage'];
$found = [];
foreach ($searchPaths as $path) {
    $files = glob($path . '/*.xlsx') ?: [];
    $found = array_merge($found, $files);
}

if (empty($found)) {
    // Create a minimal test file using PhpSpreadsheet
    echo "No xlsx files found. Creating test file...\n";
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setCellValue('A1', 'Nome');
    $sheet->setCellValue('B1', 'CPF');
    $sheet->setCellValue('C1', 'Email');
    $sheet->setCellValue('A2', 'Cliente Teste');
    $sheet->setCellValue('B2', '12345678900');
    $sheet->setCellValue('C2', 'cliente@teste.com');
    
    $testFile = sys_get_temp_dir() . '/test_import.xlsx';
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save($testFile);
    echo "Test file created at: $testFile\n";
    $found = [$testFile];
}

echo "Testing readExcelAsRecords with: " . $found[0] . "\n";
try {
    $records = App\Services\ImporterService::readExcelAsRecords($found[0]);
    echo "SUCCESS! Read " . count($records) . " records\n";
    foreach ($records as $i => $row) {
        echo "  Row " . ($i + 1) . ": " . json_encode($row) . "\n";
    }
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
