<?php
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
$sheet = IOFactory::load('C:/Users/Administrator/Downloads/Contas_a_Receber_ficticio.xlsx')->getActiveSheet();
foreach (['A3','B3','C3','D3','E3','F3','G3','H3','M3','F6','G6','H6'] as $cell) {
    $obj = $sheet->getCell($cell);
    echo $cell . ' raw=' . var_export($obj->getValue(), true) . ' formatted=' . var_export($obj->getFormattedValue(), true) . PHP_EOL;
}
