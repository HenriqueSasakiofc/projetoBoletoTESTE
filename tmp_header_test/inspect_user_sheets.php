<?php
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$files = [
  'clientes' => 'C:/Users/Administrator/Downloads/Clientes_ficticio.xlsx',
  'recebiveis' => 'C:/Users/Administrator/Downloads/Contas_a_Receber_ficticio.xlsx',
];

foreach ($files as $label => $file) {
    echo "=== {$label} ===\n";
    $sheet = IOFactory::load($file)->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, false);
    for ($i = 0; $i < min(8, count($rows)); $i++) {
        echo json_encode($rows[$i], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
    echo PHP_EOL;
}
