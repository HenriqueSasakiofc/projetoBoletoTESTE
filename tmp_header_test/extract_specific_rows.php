<?php
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
$customerSheet = IOFactory::load('C:/Users/Administrator/Downloads/Clientes_ficticio.xlsx')->getActiveSheet()->toArray(null, true, true, false);
$receivableSheet = IOFactory::load('C:/Users/Administrator/Downloads/Contas_a_Receber_ficticio.xlsx')->getActiveSheet()->toArray(null, true, true, false);
echo json_encode([
  'cliente_row' => $customerSheet[2],
  'receivable_row' => $receivableSheet[2],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
